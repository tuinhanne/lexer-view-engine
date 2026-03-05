<?php

declare(strict_types=1);

namespace Wik\Lexer\Engine;

use Wik\Lexer\Contracts\EscaperInterface;
use Wik\Lexer\Exceptions\TemplateRuntimeException;
use Wik\Lexer\Runtime\ComponentManager;
use Wik\Lexer\Runtime\SectionManager;
use Wik\Lexer\Security\HtmlEscaper;

/**
 * Per-render environment that bridges compiled templates and the runtime managers.
 *
 * An instance of this class is available in every compiled template as $__env.
 * It provides the full runtime API for layouts, sections, push-stacks,
 * components, named slots, and output escaping.
 *
 * Lifecycle:
 *   1. ViewEngine creates a fresh Environment for each top-level render() call.
 *   2. The compiled template file is included with $__env pointing to this object.
 *   3. After inclusion, ViewEngine checks hasLayout(); if true, it renders the
 *      parent layout reusing the same Environment so sections remain available.
 *
 * Infinite-layout-loop prevention:
 *   addToLayoutChain() is called with each template path before rendering.
 *   A duplicate path triggers TemplateRuntimeException.
 */
final class Environment
{
    /** Name of the parent layout, set by extend() */
    private ?string $layout = null;

    /** Paths that have been rendered in the current layout chain */
    private array $layoutChain = [];

    private EscaperInterface $escaper;

    public function __construct(
        private readonly SectionManager $sectionManager,
        private readonly ComponentManager $componentManager,
        ?EscaperInterface $escaper = null,
        private readonly mixed $includeRenderer = null,
    ) {
        $this->escaper = $escaper ?? new HtmlEscaper();
    }

    // -----------------------------------------------------------------------
    // Layout API
    // -----------------------------------------------------------------------

    /**
     * Declare that this template extends a named layout.
     * Called from the compiled output of ExtendsNode.
     */
    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function hasLayout(): bool
    {
        return $this->layout !== null;
    }

    /**
     * Return the parent layout name and clear it to prevent re-processing.
     */
    public function consumeLayout(): ?string
    {
        $layout       = $this->layout;
        $this->layout = null;

        return $layout;
    }

    /**
     * Track a template path in the layout chain.
     *
     * @throws TemplateRuntimeException  on infinite loop detection
     */
    public function addToLayoutChain(string $path): void
    {
        if (in_array($path, $this->layoutChain, true)) {
            throw TemplateRuntimeException::infiniteLayoutLoop($path);
        }

        $this->layoutChain[] = $path;
    }

    public function isInLayoutChain(string $path): bool
    {
        return in_array($path, $this->layoutChain, true);
    }

    // -----------------------------------------------------------------------
    // Output escaping API
    // -----------------------------------------------------------------------

    /**
     * Escape a value for safe HTML output.
     * Called from every {{ expr }} compiled echo node.
     */
    public function escape(mixed $value): string
    {
        return $this->escaper->escape($value);
    }

    public function setEscaper(EscaperInterface $escaper): void
    {
        $this->escaper = $escaper;
    }

    // -----------------------------------------------------------------------
    // Section API  (delegates to SectionManager)
    // -----------------------------------------------------------------------

    public function startSection(string $name): void
    {
        $this->sectionManager->startSection($name);
    }

    public function endSection(): void
    {
        $this->sectionManager->endSection();
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sectionManager->yieldSection($name, $default);
    }

    public function hasSection(string $name): bool
    {
        return $this->sectionManager->hasSection($name);
    }

    /**
     * Return the parent layout's version of the currently open section.
     * Used by #parent inside a #section block.
     */
    public function parentSection(): string
    {
        return $this->sectionManager->parentSection();
    }

    // -----------------------------------------------------------------------
    // Push stack API  (delegates to SectionManager)
    // -----------------------------------------------------------------------

    public function startPush(string $name): void
    {
        $this->sectionManager->startPush($name);
    }

    public function endPush(): void
    {
        $this->sectionManager->endPush();
    }

    public function yieldStack(string $name, string $default = ''): string
    {
        return $this->sectionManager->yieldStack($name, $default);
    }

    // -----------------------------------------------------------------------
    // Component API  (delegates to ComponentManager)
    // -----------------------------------------------------------------------

    public function startComponent(string $name, array $props = []): void
    {
        $this->componentManager->startComponent($name, $props);
    }

    public function startSlot(string $name): void
    {
        $this->componentManager->startSlot($name);
    }

    public function endSlot(): void
    {
        $this->componentManager->endSlot();
    }

    public function endComponent(): string
    {
        return $this->componentManager->endComponent();
    }

    public function renderComponent(
        string $name,
        array $props = [],
        array $namedSlots = [],
        string $defaultSlot = '',
    ): string {
        return $this->componentManager->renderComponent($name, $props, $namedSlots, $defaultSlot);
    }

    // -----------------------------------------------------------------------
    // Include API  (#include, #includeIf, #includeWhen, #includeFirst)
    // -----------------------------------------------------------------------

    /**
     * Render and return a named template.
     *
     * @param array<string, mixed> $data  Additional variables merged into the include scope
     */
    public function include(string $name, array $data = []): string
    {
        if ($this->includeRenderer === null) {
            return '';
        }

        return ($this->includeRenderer)($name, $data, 'include');
    }

    /**
     * Render a named template only if it exists; silently return '' otherwise.
     *
     * @param array<string, mixed> $data
     */
    public function includeIf(string $name, array $data = []): string
    {
        if ($this->includeRenderer === null) {
            return '';
        }

        return ($this->includeRenderer)($name, $data, 'includeIf');
    }

    /**
     * Render a named template only when $condition is truthy.
     *
     * @param array<string, mixed> $data
     */
    public function includeWhen(mixed $condition, string $name, array $data = []): string
    {
        if (!$condition || $this->includeRenderer === null) {
            return '';
        }

        return ($this->includeRenderer)($name, $data, 'include');
    }

    /**
     * Render the first template from $names that exists.
     *
     * @param string[]             $names
     * @param array<string, mixed> $data
     */
    public function includeFirst(array $names, array $data = []): string
    {
        if ($this->includeRenderer === null) {
            return '';
        }

        return ($this->includeRenderer)($names, $data, 'includeFirst');
    }
}
