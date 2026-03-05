<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents an #empty / #endempty block.
 *
 * Template syntax:
 *   #empty($items)
 *     <p>No items found.</p>
 *   #endempty
 *
 * Compiled output:
 *   <?php if (empty($items)): ?>
 *     <p>No items found.</p>
 *   <?php endif; ?>
 *
 * Named CheckEmptyNode to avoid collision with PHP's empty() language construct.
 */
final class CheckEmptyNode extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        public readonly string $expression,
        public readonly array $children,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        $out  = '<?php if (empty(' . $this->expression . ')): ?>';
        $out .= $this->compileChildren($this->children);
        $out .= '<?php endif; ?>';

        return $out;
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
