<?php

declare(strict_types=1);

namespace Wik\Lexer\Cache;

use Wik\Lexer\Contracts\CacheInterface;
use Wik\Lexer\Exceptions\CompilerException;

/**
 * File-system backed template cache.
 *
 * Compiled PHP files are stored as {cacheDirectory}/{hash}.php where the
 * hash is derived from the cache key.  Writes are atomic (write to a temp
 * file, then rename) so concurrent requests cannot read a partially written
 * cache file.
 *
 * Index file ({cacheDirectory}/index.php) maps template paths to compiled
 * file paths and is used in production mode to skip source-level checks.
 */
final class FileCache implements CacheInterface
{
    private bool $indexLoaded    = false;

    /** @var array<string, string> template-path => compiled-path */
    private array $index = [];

    public function __construct(
        private readonly string $directory,
    ) {
    }

    // -----------------------------------------------------------------------
    // CacheInterface
    // -----------------------------------------------------------------------

    public function has(string $key): bool
    {
        $path = $this->compiledPath($key);

        return file_exists($path);
    }

    public function path(string $key): ?string
    {
        $path = $this->compiledPath($key);

        return file_exists($path) ? $path : null;
    }

    public function put(string $key, string $compiledContent): string
    {
        $this->ensureDirectory();

        $path = $this->compiledPath($key);

        if (!$this->atomicWrite($path, $compiledContent)) {
            throw CompilerException::compilationFailed($key, 'Cannot write to cache directory.');
        }

        return $path;
    }

    public function forget(string $key): void
    {
        $path = $this->compiledPath($key);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    public function flush(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.php');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        $astFiles = glob($this->directory . DIRECTORY_SEPARATOR . '*.ast');

        if ($astFiles !== false) {
            foreach ($astFiles as $file) {
                @unlink($file);
            }
        }

        $this->index       = [];
        $this->indexLoaded = false;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    // -----------------------------------------------------------------------
    // Precompiled index (production mode)
    // -----------------------------------------------------------------------

    /**
     * Check the precompiled index for a template path.
     * Returns the compiled file path if registered, or null.
     */
    public function indexLookup(string $templatePath): ?string
    {
        $this->loadIndex();

        $compiled = $this->index[$templatePath] ?? null;

        if ($compiled !== null && file_exists($compiled)) {
            return $compiled;
        }

        return null;
    }

    /**
     * Register a template → compiled path mapping in the index.
     */
    public function indexRegister(string $templatePath, string $compiledPath): void
    {
        $this->loadIndex();

        $this->index[$templatePath] = $compiledPath;
        $this->saveIndex();
    }

    /**
     * Remove all entries from the precompiled index.
     */
    public function flushIndex(): void
    {
        $this->index       = [];
        $this->indexLoaded = true;
        $this->saveIndex();
    }

    // -----------------------------------------------------------------------
    // AST helpers
    // -----------------------------------------------------------------------

    public function astPath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . md5($key) . '.ast';
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private function compiledPath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . md5($key) . '.php';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
                throw CompilerException::cacheDirectoryNotWritable($this->directory);
            }
        }

        if (!is_writable($this->directory)) {
            throw CompilerException::cacheDirectoryNotWritable($this->directory);
        }
    }

    private function indexPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'index.php';
    }

    private function loadIndex(): void
    {
        if ($this->indexLoaded) {
            return;
        }

        $this->indexLoaded = true;
        $path              = $this->indexPath();

        if (!file_exists($path)) {
            $this->index = [];

            return;
        }

        $data = @include $path;

        $this->index = is_array($data) ? $data : [];
    }

    private function saveIndex(): void
    {
        $this->ensureDirectory();

        $exports = var_export($this->index, true);
        $content = "<?php\n\n// Wik/Lexer precompiled view index — do not edit.\n\nreturn {$exports};\n";

        $this->atomicWrite($this->indexPath(), $content);
    }

    /**
     * Write $data to $path atomically: write to a temp file then rename.
     * Returns true on success, false if the write failed.
     */
    private function atomicWrite(string $path, string $data): bool
    {
        $tmp = $path . '.tmp.' . getmypid();

        if (file_put_contents($tmp, $data, LOCK_EX) === false) {
            return false;
        }

        return rename($tmp, $path);
    }
}
