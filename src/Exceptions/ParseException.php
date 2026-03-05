<?php

declare(strict_types=1);

namespace Wik\Lexer\Exceptions;

class ParseException extends LexException
{
    public static function unexpectedDirective(string $name, int $line): self
    {
        return new self("Unexpected directive #{$name} on line {$line}. No matching opening block.");
    }

    public static function unexpectedClosingTag(string $name, int $line): self
    {
        return new self("Unexpected closing tag </{$name}> on line {$line}. No matching opening tag.");
    }

    public static function mismatchedClosingTag(string $expected, string $actual, int $line): self
    {
        return new self(
            "Mismatched closing tag on line {$line}: expected </{$expected}>, got </{$actual}>."
        );
    }

    public static function unclosedBlock(string $type, int $line): self
    {
        return new self("Unclosed {$type} block opened on line {$line}. Missing closing directive.");
    }

    public static function unknownDirective(string $name, int $line): self
    {
        return new self("Unknown directive #{$name} on line {$line}. Register it via \$lexer->directive().");
    }
}
