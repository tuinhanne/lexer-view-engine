<?php

declare(strict_types=1);

namespace Wik\Lexer\Exceptions;

class LexerException extends LexException
{
    public static function unterminatedEcho(int $line): self
    {
        return new self("Unterminated echo expression {{ ... }} starting on line {$line}.");
    }

    public static function unterminatedRawEcho(int $line): self
    {
        return new self("Unterminated raw echo expression {!! ... !!} starting on line {$line}.");
    }

    public static function unterminatedComponent(string $name, int $line): self
    {
        return new self("Unterminated component tag <{$name}> starting on line {$line}.");
    }
}
