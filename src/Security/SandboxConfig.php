<?php

declare(strict_types=1);

namespace Wik\Lexer\Security;

/**
 * Immutable configuration object for sandbox mode.
 *
 * In sandbox mode the engine restricts what is allowed inside templates:
 *   - Raw echo ({!! ... !!}) can be disabled
 *   - Expressions can be validated against a function whitelist
 *   - Custom directives can be blocked or limited to an allowlist
 *
 * Build with the fluent factory:
 *
 *   $config = SandboxConfig::secure()
 *       ->withAllowedFunctions(['date', 'number_format', 'strtoupper'])
 *       ->withAllowedDirectives(['datetime', 'currency']);
 */
final class SandboxConfig
{
    /**
     * @param bool          $allowRawEcho          Allow {!! raw !!} output
     * @param string[]|null $allowedFunctions       Null = all functions allowed; [] = none
     * @param bool          $allowCustomDirectives  Allow user-registered directives
     * @param string[]|null $allowedDirectives      Null = all directives allowed; [] = none
     * @param bool          $allowPhpTagsInText     Allow raw <?php ?> blocks in template text
     */
    public function __construct(
        public readonly bool $allowRawEcho = true,
        public readonly ?array $allowedFunctions = null,
        public readonly bool $allowCustomDirectives = true,
        public readonly ?array $allowedDirectives = null,
        public readonly bool $allowPhpTagsInText = true,
    ) {
    }

    // -----------------------------------------------------------------------
    // Factory methods
    // -----------------------------------------------------------------------

    /**
     * Permissive configuration (default) — no restrictions.
     */
    public static function permissive(): self
    {
        return new self(
            allowRawEcho: true,
            allowedFunctions: null,
            allowCustomDirectives: true,
            allowedDirectives: null,
            allowPhpTagsInText: true,
        );
    }

    /**
     * Secure/sandbox configuration — restrictive defaults.
     *
     * Raw echo is forbidden.  No PHP function calls allowed unless explicitly
     * whitelisted.  PHP tags in text are forbidden.
     */
    public static function secure(): self
    {
        return new self(
            allowRawEcho: false,
            allowedFunctions: [],      // empty = no functions allowed until whitelisted
            allowCustomDirectives: false,
            allowedDirectives: [],
            allowPhpTagsInText: false,
        );
    }

    // -----------------------------------------------------------------------
    // Fluent modifiers — each returns a new immutable instance
    // -----------------------------------------------------------------------

    public function withRawEcho(bool $allow): self
    {
        return new self(
            $allow,
            $this->allowedFunctions,
            $this->allowCustomDirectives,
            $this->allowedDirectives,
            $this->allowPhpTagsInText,
        );
    }

    /** @param string[] $functions */
    public function withAllowedFunctions(array $functions): self
    {
        return new self(
            $this->allowRawEcho,
            $functions,
            $this->allowCustomDirectives,
            $this->allowedDirectives,
            $this->allowPhpTagsInText,
        );
    }

    /** @param string[] $directives */
    public function withAllowedDirectives(array $directives): self
    {
        return new self(
            $this->allowRawEcho,
            $this->allowedFunctions,
            $this->allowCustomDirectives,
            $directives,
            $this->allowPhpTagsInText,
        );
    }

    public function withCustomDirectives(bool $allow): self
    {
        return new self(
            $this->allowRawEcho,
            $this->allowedFunctions,
            $allow,
            $this->allowedDirectives,
            $this->allowPhpTagsInText,
        );
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    public function isFunctionAllowed(string $name): bool
    {
        if ($this->allowedFunctions === null) {
            return true; // all allowed
        }

        return in_array($name, $this->allowedFunctions, true);
    }

    public function isDirectiveAllowed(string $name): bool
    {
        if ($this->allowedDirectives === null) {
            return true; // all allowed
        }

        return in_array($name, $this->allowedDirectives, true);
    }
}
