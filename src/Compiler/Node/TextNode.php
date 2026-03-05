<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a raw text / HTML segment that passes through to compiled output unchanged.
 */
final class TextNode extends Node
{
    public function __construct(
        public readonly string $text,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        return $this->text;
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
