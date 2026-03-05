<?php

declare(strict_types=1);

namespace Wik\Lexer\Exceptions;

class CompilerException extends LexException
{
    public static function cacheDirectoryNotWritable(string $path): self
    {
        return new self("Cache directory '{$path}' is not writable or could not be created.");
    }

    public static function compilationFailed(string $template, string $reason): self
    {
        return new self("Compilation failed for template '{$template}': {$reason}");
    }
}
