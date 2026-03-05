<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #php / #endphp raw PHP block.
 *
 * Template syntax:
 *   #php
 *     $total = array_sum($prices);
 *     $label = strtoupper($category);
 *   #endphp
 *
 * The code between the markers is emitted verbatim inside <?php ... ?> tags.
 * Unlike {{ expr }}, the content is NOT escaped or validated.
 *
 * Note: In sandbox mode the AstValidator will reject PhpNode if the sandbox
 * configuration does not allow raw PHP blocks.
 */
final class PhpNode extends Node
{
    public function __construct(
        public readonly string $code,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        return '<?php ' . $this->code . ' ?>';
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
