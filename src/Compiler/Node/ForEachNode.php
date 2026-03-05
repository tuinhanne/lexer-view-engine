<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #foreach / #endforeach loop.
 *
 * The expression is the raw PHP foreach expression, e.g. "$items as $item".
 */
final class ForEachNode extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        public readonly string $expression,
        public readonly array $children,
    ) {
    }

    public function compile(): string
    {
        $iterable = $this->extractIterable($this->expression);

        // Push current $loop onto the stack so nested loops can access it via $loop->parent
        $out  = '<?php $__loop_stack[] = $loop ?? null;';
        $out .= ' $__loop_count = ' . ($iterable !== null
            ? 'is_countable(' . $iterable . ') ? count(' . $iterable . ') : 0'
            : '0') . ';';
        $out .= ' $__loop_i = 0; ?>';

        $out .= '<?php foreach (' . $this->expression . '): ?>';

        // Rebuild $loop on every iteration
        $out .= '<?php $loop = (object)['
            . "'index' => \$__loop_i,"
            . "'iteration' => \$__loop_i + 1,"
            . "'remaining' => \$__loop_count - \$__loop_i - 1,"
            . "'count' => \$__loop_count,"
            . "'first' => \$__loop_i === 0,"
            . "'last' => \$__loop_i === \$__loop_count - 1,"
            . "'even' => \$__loop_i % 2 === 0,"
            . "'odd' => \$__loop_i % 2 !== 0,"
            . "'depth' => (isset(\$__loop_stack) && end(\$__loop_stack) !== false && end(\$__loop_stack) !== null)"
            . ' ? end($__loop_stack)->depth + 1 : 1,'
            . "'parent' => (isset(\$__loop_stack) ? end(\$__loop_stack) : null) ?: null,"
            . ']; ++$__loop_i; ?>';

        $out .= $this->compileChildren($this->children);
        $out .= '<?php endforeach; ?>';

        // Restore the parent $loop (null if we were in the outermost loop)
        $out .= '<?php $loop = array_pop($__loop_stack); ?>';

        return $out;
    }

    /**
     * Extract the iterable expression from "expr as alias" to enable $loop->count.
     * Returns null if the expression cannot be parsed.
     */
    private function extractIterable(string $expression): ?string
    {
        if (preg_match('/^(.+?)\s+as\s+/i', $expression, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
