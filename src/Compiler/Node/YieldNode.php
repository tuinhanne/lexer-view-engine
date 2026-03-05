<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #yield('name') or #yield('name', 'default') placeholder
 * inside a layout template.
 *
 * At runtime $__env->yieldSection() returns the captured section content
 * registered by child templates, falling back to $default when the section
 * has not been defined.
 *
 * The default value may be a literal string or a PHP expression:
 *   #yield('title', 'Untitled')            → literal default
 *   #yield('title', $config->siteTitle)    → expression default
 */
final class YieldNode extends Node
{
    /**
     * @param string $name           Section name to yield
     * @param string $default        Default value if section not defined
     * @param bool   $defaultIsExpr  True when $default is a PHP expression
     */
    public function __construct(
        public readonly string $name,
        public readonly string $default = '',
        public readonly bool $defaultIsExpr = false,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        $escapedName = addslashes($this->name);

        if ($this->defaultIsExpr) {
            $defaultPhp = $this->default !== '' ? $this->default : "''";

            return "<?php echo \$__env->yieldSection('{$escapedName}', {$defaultPhp}); ?>";
        }

        $escapedDefault = addslashes($this->default);

        return "<?php echo \$__env->yieldSection('{$escapedName}', '{$escapedDefault}'); ?>";
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
