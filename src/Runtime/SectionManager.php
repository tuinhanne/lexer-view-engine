<?php

declare(strict_types=1);

namespace Wik\Lexer\Runtime;

use Wik\Lexer\Exceptions\LexException;

/**
 * Manages named content sections and push-stacks for the layout system.
 *
 * Sections (via #section / #endsection):
 *   Templates that extend a layout capture content into named sections.
 *   Later definitions override earlier ones (child overrides parent).
 *   The layout retrieves content via yieldSection().
 *
 * Push stacks (via #push / #endpush):
 *   Multiple calls push content onto a named stack.  All pushed content is
 *   concatenated in push order.  The layout retrieves it via yieldStack().
 *
 * Parent section (#parent inside #section):
 *   When a child template redefines a section that was already captured, the
 *   previous content is stored as "parent content" and available via
 *   parentSection() — allowing child templates to extend, not just replace.
 */
final class SectionManager
{
    /** @var array<string, string> Completed section content keyed by name */
    private array $sections = [];

    /** @var array<string, string> Parent content for sections being overridden */
    private array $parentContent = [];

    /** @var string[] Stack of section names currently being captured */
    private array $stack = [];

    /** @var array<string, string[]> Named push stacks: name => [content, ...] */
    private array $pushStacks = [];

    /** @var string[] Stack of push names currently being captured */
    private array $pushStack = [];

    /** Current template file being executed — set by ViewEngine. */
    private string $currentFile = '';

    /**
     * Debug hooks.
     *
     * Supported events:
     *   onSectionEnd(string $name, string $content, string $file): void
     *   onSectionYield(string $name, string $file):                  void
     *
     * @var array<string, callable[]>
     */
    private array $hooks = [];

    // -----------------------------------------------------------------------
    // Hook API
    // -----------------------------------------------------------------------

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
    }

    public function getCurrentFile(): string
    {
        return $this->currentFile;
    }

    public function addHook(string $event, callable $fn): void
    {
        $this->hooks[$event][] = $fn;
    }

    private function fireHook(string $event, mixed ...$args): void
    {
        foreach ($this->hooks[$event] ?? [] as $fn) {
            $fn(...$args);
        }
    }

    // -----------------------------------------------------------------------
    // Section capture API
    // -----------------------------------------------------------------------

    /**
     * Begin capturing output into a named section.
     *
     * If a section with the same name already exists, its content is saved
     * as the "parent content" accessible via parentSection().
     *
     * @throws LexException  if the same section name is already open
     */
    public function startSection(string $name): void
    {
        if (in_array($name, $this->stack, true)) {
            throw new LexException(
                "Section '{$name}' is already open. Sections cannot be nested with the same name."
            );
        }

        // Save current content as parent before overriding
        if (isset($this->sections[$name])) {
            $this->parentContent[$name] = $this->sections[$name];
        }

        $this->stack[] = $name;
        ob_start();
    }

    /**
     * End the innermost open section and store its captured content.
     *
     * @throws LexException  if no section is currently open
     */
    public function endSection(): void
    {
        if (empty($this->stack)) {
            throw new LexException('endSection() called without a matching startSection().');
        }

        $name    = array_pop($this->stack);
        $content = ob_get_clean();

        // Child definitions override parent definitions
        $this->sections[$name] = $content === false ? '' : $content;

        $this->fireHook('onSectionEnd', $name, $this->sections[$name], $this->currentFile);
    }

    // -----------------------------------------------------------------------
    // Section yield API
    // -----------------------------------------------------------------------

    /**
     * Return the captured content for a section, or $default if not defined.
     */
    public function yieldSection(string $name, string $default = ''): string
    {
        $this->fireHook('onSectionYield', $name, $this->currentFile);
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Return the parent layout's version of the current innermost section.
     * Called via #parent inside a section body.
     */
    public function parentSection(): string
    {
        $currentSection = end($this->stack);

        if ($currentSection === false) {
            return '';
        }

        return $this->parentContent[$currentSection] ?? '';
    }

    // -----------------------------------------------------------------------
    // Push stack API
    // -----------------------------------------------------------------------

    /**
     * Begin capturing output for a named push stack.
     *
     * @throws LexException  if the same push stack name is already open
     */
    public function startPush(string $name): void
    {
        if (in_array($name, $this->pushStack, true)) {
            throw new LexException(
                "Push stack '{$name}' is already open. Push stacks cannot be nested with the same name."
            );
        }

        $this->pushStack[] = $name;
        ob_start();
    }

    /**
     * End the innermost push capture and append its content to the stack.
     *
     * @throws LexException  if no push is currently open
     */
    public function endPush(): void
    {
        if (empty($this->pushStack)) {
            throw new LexException('endPush() called without a matching startPush().');
        }

        $name    = array_pop($this->pushStack);
        $content = ob_get_clean();

        if ($content !== false && $content !== '') {
            $this->pushStacks[$name][] = $content;
        }
    }

    /**
     * Return all content pushed to the named stack, concatenated in order.
     */
    public function yieldStack(string $name, string $default = ''): string
    {
        if (empty($this->pushStacks[$name])) {
            return $default;
        }

        return implode('', $this->pushStacks[$name]);
    }

    // -----------------------------------------------------------------------
    // Inspection / reset
    // -----------------------------------------------------------------------

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->sections;
    }

    /** @return array<string, string[]> */
    public function allStacks(): array
    {
        return $this->pushStacks;
    }

    /**
     * Reset all captured sections, stacks, and the open-section trackers.
     * Called by the ViewEngine when starting a fresh top-level render.
     */
    public function reset(): void
    {
        while (!empty($this->stack)) {
            ob_end_clean();
            array_pop($this->stack);
        }

        while (!empty($this->pushStack)) {
            ob_end_clean();
            array_pop($this->pushStack);
        }

        $this->sections      = [];
        $this->parentContent = [];
        $this->pushStacks    = [];
    }
}
