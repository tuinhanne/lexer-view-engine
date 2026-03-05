<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #stack('name') output placeholder.
 *
 * Outputs all content pushed to the named stack via #push / #endpush
 * directives.  Unlike #yield, stacks accumulate rather than replace.
 *
 * Supports an optional default value used when nothing has been pushed:
 *   #stack('scripts', '')
 *
 * Compiled output calls $__env->yieldStack() at runtime.
 */
final class StackNode extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly string $default = '',
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        $escaped        = addslashes($this->name);
        $escapedDefault = addslashes($this->default);

        return "<?php echo \$__env->yieldStack('{$escaped}', '{$escapedDefault}'); ?>";
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
