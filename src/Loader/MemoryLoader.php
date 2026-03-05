<?php

declare(strict_types=1);

namespace Wik\Lexer\Loader;

use Wik\Lexer\Contracts\LoaderInterface;
use Wik\Lexer\Exceptions\ViewException;

/**
 * In-memory template loader.
 *
 * Stores template source strings in an associative array.  Useful for:
 *   - Unit testing without touching the filesystem
 *   - Dynamically generated templates
 *   - Embedding templates directly in application code
 *
 * Example:
 *   $loader = new MemoryLoader();
 *   $loader->set('greeting', 'Hello, {{ $name }}!');
 */
final class MemoryLoader implements LoaderInterface
{
    /** @var array<string, string> name => source */
    private array $templates = [];

    public function set(string $name, string $source): void
    {
        $this->templates[$name] = $source;
    }

    public function remove(string $name): void
    {
        unset($this->templates[$name]);
    }

    public function all(): array
    {
        return $this->templates;
    }

    // -----------------------------------------------------------------------
    // LoaderInterface
    // -----------------------------------------------------------------------

    public function load(string $name): string
    {
        if (!$this->exists($name)) {
            throw ViewException::templateNotFound($name, ['memory']);
        }

        return $this->templates[$name];
    }

    public function exists(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    public function getPath(string $name): ?string
    {
        return null; // Memory loader has no filesystem paths
    }

    public function getCacheKey(string $name): string
    {
        return md5($name . ':' . ($this->templates[$name] ?? ''));
    }
}
