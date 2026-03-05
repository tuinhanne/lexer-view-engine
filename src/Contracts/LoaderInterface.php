<?php

declare(strict_types=1);

namespace Wik\Lexer\Contracts;

use Wik\Lexer\Exceptions\ViewException;

/**
 * Template source loader contract.
 *
 * A loader resolves a template name to its source code string.
 * Multiple implementations allow loading from the filesystem, memory,
 * databases, or namespaced view packages.
 */
interface LoaderInterface
{
    /**
     * Load and return the raw template source for the given name.
     *
     * @throws ViewException  when the template cannot be found
     */
    public function load(string $name): string;

    /**
     * Return true when the named template can be found.
     */
    public function exists(string $name): bool;

    /**
     * Return the resolved filesystem path for the template, or null if the
     * loader does not work with files (e.g. MemoryLoader).
     */
    public function getPath(string $name): ?string;

    /**
     * Return a cache key that uniquely identifies the template source.
     * Implementations may include a content hash or file mtime.
     */
    public function getCacheKey(string $name): string;
}
