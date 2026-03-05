<?php

declare(strict_types=1);

namespace Wik\Lexer\Loader;

use Wik\Lexer\Config\LexConfig;
use Wik\Lexer\Contracts\LoaderInterface;
use Wik\Lexer\Exceptions\ViewException;

/**
 * Filesystem-backed template loader.
 *
 * Resolves view names to absolute file paths using the configured directory
 * list and file extension.  Supports dot-notation for subdirectory separators:
 *   'layouts.main'  →  {dir}/layouts/main.{ext}
 *
 * This is the default loader wired into ViewEngine by Lexer::buildEngine().
 * View paths come from lex.config.json (viewPaths field) — no hardcoded
 * directories are assumed.
 *
 * getCacheKey() returns md5(path:mtime) so the compiled cache is invalidated
 * whenever a source file is modified, without needing to read its full content.
 */
final class FileLoader implements LoaderInterface
{
    /** @var string[] */
    private array $directories;

    /**
     * @param string[] $directories  Absolute paths to search for template files
     * @param string   $extension    File extension without leading dot
     */
    public function __construct(
        array $directories = [],
        private readonly string $extension = LexConfig::DEFAULT_EXTENSION,
    ) {
        $this->directories = array_map(fn($d) => rtrim($d, '/\\'), $directories);
    }

    public function addDirectory(string $path): void
    {
        $this->directories[] = rtrim($path, '/\\');
    }

    // -----------------------------------------------------------------------
    // LoaderInterface
    // -----------------------------------------------------------------------

    public function load(string $name): string
    {
        $path = $this->getPath($name);

        if ($path === null) {
            throw ViewException::templateNotFound($name, $this->directories);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw ViewException::templateNotFound($name, $this->directories);
        }

        return $content;
    }

    public function exists(string $name): bool
    {
        return $this->getPath($name) !== null;
    }

    public function getPath(string $name): ?string
    {
        // Absolute path provided directly
        if (file_exists($name)) {
            return $name;
        }

        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name);

        foreach ($this->directories as $dir) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $relative . '.' . $this->extension;

            if (file_exists($candidate)) {
                return $candidate;
            }

            // Try without appending extension (caller may have included it)
            $bare = $dir . DIRECTORY_SEPARATOR . $relative;

            if (file_exists($bare)) {
                return $bare;
            }
        }

        return null;
    }

    public function getCacheKey(string $name): string
    {
        $path = $this->getPath($name);

        if ($path === null) {
            return md5($name);
        }

        // Include mtime so cache is invalidated when file changes
        $mtime = @filemtime($path);

        return md5($path . ':' . ($mtime !== false ? $mtime : '0'));
    }

    /** @return string[] */
    public function getDirectories(): array
    {
        return $this->directories;
    }
}
