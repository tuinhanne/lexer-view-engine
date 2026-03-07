<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents an #isset / #endisset block.
 *
 * Template syntax:
 *   #isset($user)
 *     <p>Hello, {{ $user->name }}</p>
 *   #endisset
 *
 * Compiled output:
 *   <?php if (isset($user)): ?>
 *     <p>Hello, <?php echo $__env->escape($user->name); ?></p>
 *   <?php endif; ?>
 */
final class IssetNode extends Node
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
        $out  = '<?php if (isset(' . $this->expression . ')): ?>';
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
