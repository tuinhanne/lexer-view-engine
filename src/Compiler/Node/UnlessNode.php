<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents an #unless / #endunless block (inverse if).
 *
 * Template syntax:
 *   #unless($user->isAdmin())
 *     <p>Access restricted.</p>
 *   #endunless
 *
 * Compiled output:
 *   <?php if (!($user->isAdmin())): ?>
 *     <p>Access restricted.</p>
 *   <?php endif; ?>
 */
final class UnlessNode extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        public readonly string $condition,
        public readonly array $children,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        $out  = '<?php if (!(' . $this->condition . ')): ?>';
        $out .= $this->compileChildren($this->children);
        $out .= '<?php endif; ?>';

        return $out;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    /** @return Node[] */
    public function getChildren(): array
    {
        return $this->children;
    }
}
