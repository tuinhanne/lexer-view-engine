<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #while / #endwhile loop.
 *
 * Template syntax:
 *   #while ($condition):
 *     ... body ...
 *   #endwhile
 *
 * Compiled output:
 *   <?php while ($condition): ?>
 *     ... body ...
 *   <?php endwhile; ?>
 */
final class WhileNode extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        public readonly string $condition,
        public readonly array $children,
    ) {
    }

    public function compile(): string
    {
        $out  = '<?php while (' . $this->condition . '): ?>';
        $out .= $this->compileChildren($this->children);
        $out .= '<?php endwhile; ?>';

        return $out;
    }
}
