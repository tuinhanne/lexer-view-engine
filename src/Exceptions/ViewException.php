<?php

declare(strict_types=1);

namespace Wik\Lexer\Exceptions;

class ViewException extends LexException
{
    public static function templateNotFound(string $name, array $paths): self
    {
        $searched = implode(', ', array_map(fn($p) => "'{$p}'", $paths));

        return new self("Template '{$name}' not found. Searched in: [{$searched}].");
    }

    public static function componentNotFound(string $name, array $paths): self
    {
        $searched = implode(', ', array_map(fn($p) => "'{$p}'", $paths));

        return new self("Component '{$name}' not found. Searched in: [{$searched}].");
    }

    public static function noCacheDirectory(): self
    {
        return new self('No cache directory configured. Call $lexer->cache(\'path/to/cache\') before rendering.');
    }

    public static function noViewPaths(): self
    {
        return new self('No view paths configured. Call $lexer->paths([\'path/to/views\']) before rendering.');
    }
}
