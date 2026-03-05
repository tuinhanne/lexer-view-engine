<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #continue directive.
 *
 * Valid inside #foreach and #while blocks.
 *
 * Template syntax:
 *   #continue
 *   #continue(2)   — continue N levels up (optional)
 *
 * Compiled output:
 *   <?php continue; ?>
 *   <?php continue 2; ?>
 */
final class ContinueNode extends Node
{
    public function __construct(
        public readonly int $levels = 1,
    ) {
    }

    public function compile(): string
    {
        return $this->levels > 1
            ? '<?php continue ' . $this->levels . '; ?>'
            : '<?php continue; ?>';
    }
}
