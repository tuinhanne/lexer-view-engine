<?php

declare(strict_types=1);

namespace Wik\Lexer\Contracts;

/**
 * Cache storage contract for compiled template files.
 *
 * Implementations are responsible for persisting compiled PHP files so
 * they can be retrieved across requests without recompilation.
 */
interface CacheInterface
{
    /**
     * Check whether a compiled version exists for the given key.
     */
    public function has(string $key): bool;

    /**
     * Return the absolute path to the compiled file for a key, or null if it
     * does not exist.
     */
    public function path(string $key): ?string;

    /**
     * Store compiled PHP content under the given key and return the path to
     * the stored file (suitable for include()).
     */
    public function put(string $key, string $compiledContent): string;

    /**
     * Remove the cached entry for a key.
     */
    public function forget(string $key): void;

    /**
     * Remove all cached entries.
     */
    public function flush(): void;

    /**
     * Return the directory in which compiled files are stored.
     */
    public function getDirectory(): string;
}
