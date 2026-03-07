<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #for / #endfor loop.
 *
 * Template syntax:
 *   #for($i = 0; $i < 10; $i++)
 *     ... body ...
 *   #endfor
 *
 * Compiled output:
 *   <?php for ($i = 0; $i < 10; $i++): ?>
 *     ... body ...
 *   <?php endfor; ?>
 */
final class ForNode extends Node
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
        $out  = '<?php for (' . $this->expression . '): ?>';
        $out .= $this->compileChildren($this->children);
        $out .= '<?php endfor; ?>';

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
