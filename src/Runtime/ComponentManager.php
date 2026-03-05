<?php

declare(strict_types=1);

namespace Wik\Lexer\Runtime;

use Wik\Lexer\Exceptions\LexException;
use Wik\Lexer\Exceptions\TemplateRuntimeException;
use Wik\Lexer\Exceptions\ViewException;

/**
 * Manages component rendering via output-buffering.
 *
 * Component rendering pipeline:
 *   1. $__env->startComponent('Card', $props)  — ob_start(), push frame
 *   2. [slot content is rendered into the ob buffer]
 *   3. Named slots: $__env->startSlot('header') / endSlot() partition content
 *   4. $__env->endComponent()  — ob_get_clean(), render component view
 *
 * Component classes:
 *   If a class "{ComponentName}Component" exists (or is registered), it is
 *   instantiated, mount() is called with the resolved props, and its public
 *   properties are injected into the component template scope.
 *
 * Named slots:
 *   Available in component templates as $slots['header'], $slots['footer'], …
 *   The default slot content is available as $slot (plain string).
 *
 * Recursion guard:
 *   Each component name tracks its render depth.  Exceeding MAX_DEPTH throws
 *   TemplateRuntimeException to prevent infinite recursion.
 */
final class ComponentManager
{
    public const MAX_DEPTH = 50;

    /**
     * Stack of pending component render frames.
     *
     * @var array<int, array{
     *   name: string,
     *   props: array<string, mixed>,
     *   slots: array<string, string>,
     *   current_slot: string|null,
     * }>
     */
    private array $stack = [];

    /**
     * Render depth per component name for recursion prevention.
     *
     * @var array<string, int>
     */
    private array $renderDepth = [];

    /** @var array<string, string> name → view-file-path */
    private array $componentMap = [];

    /** @var array<string, class-string> name → component class */
    private array $componentClassMap = [];

    /** Namespace prefix for auto-discovered component classes. */
    private string $componentClassNamespace = '';

    /** @var string[] */
    private array $componentPaths = [];

    private ?\Closure $viewRenderer = null;

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    public function setViewRenderer(\Closure $renderer): void
    {
        $this->viewRenderer = $renderer;
    }

    public function addComponentPath(string $path): void
    {
        $this->componentPaths[] = rtrim($path, '/\\');
    }

    public function registerComponent(string $name, string $viewPath): void
    {
        $this->componentMap[$name] = $viewPath;
    }

    /**
     * @param class-string $class
     */
    public function registerComponentClass(string $name, string $class): void
    {
        $this->componentClassMap[$name] = $class;
    }

    public function setComponentClassNamespace(string $namespace): void
    {
        $this->componentClassNamespace = rtrim($namespace, '\\');
    }

    // -----------------------------------------------------------------------
    // Runtime API — called from compiled templates via $__env
    // -----------------------------------------------------------------------

    /**
     * Begin capturing slot content for the named component.
     */
    public function startComponent(string $name, array $props = []): void
    {
        $depth = $this->renderDepth[$name] ?? 0;

        if ($depth >= self::MAX_DEPTH) {
            throw TemplateRuntimeException::componentRecursionLimit($name, self::MAX_DEPTH);
        }

        $this->renderDepth[$name] = $depth + 1;

        $this->stack[] = [
            'name'         => $name,
            'props'        => $props,
            'slots'        => [],
            'current_slot' => null,
        ];

        ob_start();
    }

    /**
     * Begin capturing a named slot inside a startComponent() / endComponent() pair.
     */
    public function startSlot(string $name): void
    {
        if (empty($this->stack)) {
            throw new LexException('startSlot() called without a matching startComponent().');
        }

        $idx = count($this->stack) - 1;

        if ($this->stack[$idx]['current_slot'] !== null) {
            throw new LexException(
                "Cannot open slot '{$name}' while slot '{$this->stack[$idx]['current_slot']}' is still open."
            );
        }

        $this->stack[$idx]['current_slot'] = $name;
        ob_start();
    }

    /**
     * End the currently open named slot.
     */
    public function endSlot(): void
    {
        if (empty($this->stack)) {
            throw new LexException('endSlot() called without a matching startComponent().');
        }

        $idx  = count($this->stack) - 1;
        $name = $this->stack[$idx]['current_slot'];

        if ($name === null) {
            throw new LexException('endSlot() called without a matching startSlot().');
        }

        $content                           = ob_get_clean() ?: '';
        $this->stack[$idx]['slots'][$name] = $content;
        $this->stack[$idx]['current_slot'] = null;
    }

    /**
     * Finish capturing the default slot, render the component, and return HTML.
     *
     * @throws LexException  if called without a matching startComponent()
     */
    public function endComponent(): string
    {
        if (empty($this->stack)) {
            throw new LexException('endComponent() called without a matching startComponent().');
        }

        $defaultSlot = ob_get_clean() ?: '';
        $frame       = array_pop($this->stack);
        $name        = $frame['name'];

        $this->renderDepth[$name] = max(0, ($this->renderDepth[$name] ?? 1) - 1);

        return $this->renderComponent($name, $frame['props'], $frame['slots'], $defaultSlot);
    }

    /**
     * Render a self-closing component (no slot content).
     */
    public function renderComponent(
        string $name,
        array $props = [],
        array $namedSlots = [],
        string $defaultSlot = '',
    ): string {
        if ($this->viewRenderer === null) {
            throw new LexException(
                'No view renderer configured on ComponentManager. This is an internal error.'
            );
        }

        $classProps = $this->resolveComponentClass($name, $props);
        $viewPath   = $this->resolveComponentPath($name);

        $data = array_merge(
            $props,
            $classProps,
            [
                'slot'  => $defaultSlot,
                'slots' => $namedSlots,
            ],
        );

        return ($this->viewRenderer)($viewPath, $data);
    }

    // -----------------------------------------------------------------------
    // Component class support
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function resolveComponentClass(string $name, array $props): array
    {
        $class = $this->componentClassMap[$name] ?? $this->guessComponentClass($name);

        if ($class === null || !class_exists($class)) {
            return [];
        }

        $instance = new $class();

        if (method_exists($instance, 'mount')) {
            $ref  = new \ReflectionMethod($instance, 'mount');
            $args = [];

            foreach ($ref->getParameters() as $param) {
                $paramName = $param->getName();

                if (array_key_exists($paramName, $props)) {
                    $args[$paramName] = $props[$paramName];
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[$paramName] = $param->getDefaultValue();
                }
            }

            $instance->mount(...$args);
        }

        $result = [];
        $ref    = new \ReflectionObject($instance);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $result[$prop->getName()] = $prop->getValue($instance);
        }

        return $result;
    }

    private function guessComponentClass(string $name): ?string
    {
        if ($this->componentClassNamespace === '') {
            return null;
        }

        // Normalize to PascalCase: "card" → "Card", "card-name" → "CardName"
        $pascal = implode('', array_map(
            ucfirst(...),
            preg_split('/[-_]/', $name) ?: [$name]
        ));

        return $this->componentClassNamespace . '\\' . $pascal . 'Component';
    }

    // -----------------------------------------------------------------------
    // Path resolution
    // -----------------------------------------------------------------------

    private function resolveComponentPath(string $name): string
    {
        if (isset($this->componentMap[$name])) {
            return $this->componentMap[$name];
        }

        $candidates = $this->buildCandidateFilenames($name);
        $searched   = [];

        foreach ($this->componentPaths as $dir) {
            foreach ($candidates as $filename) {
                $path = $dir . DIRECTORY_SEPARATOR . $filename;

                if (file_exists($path)) {
                    return $path;
                }

                $searched[] = $path;
            }
        }

        throw ViewException::componentNotFound($name, $searched);
    }

    /** @return string[] */
    private function buildCandidateFilenames(string $name): array
    {
        $candidates = [];

        $kebab        = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name) ?? $name);
        $candidates[] = $kebab . '.lex';

        if ($name !== $kebab) {
            $candidates[] = $name . '.lex';
        }

        $lower = strtolower($name);

        if ($lower !== $kebab && $lower !== $name) {
            $candidates[] = $lower . '.lex';
        }

        return array_unique($candidates);
    }
}
