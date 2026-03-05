<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #break directive.
 *
 * Valid inside #foreach, #while, and #switch blocks.
 *
 * Template syntax:
 *   #break
 *   #break(2)   — break out N levels (optional)
 *
 * Compiled output:
 *   <?php break; ?>
 *   <?php break 2; ?>
 */
final class BreakNode extends Node
{
    public function __construct(
        public readonly int $levels = 1,
    ) {
    }

    public function compile(): string
    {
        return $this->levels > 1
            ? '<?php break ' . $this->levels . '; ?>'
            : '<?php break; ?>';
    }
}
