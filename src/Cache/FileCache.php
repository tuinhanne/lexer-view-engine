<?php

declare(strict_types=1);

namespace Wik\Lexer\Cache;

use Wik\Lexer\Contracts\CacheInterface;
use Wik\Lexer\Exceptions\CompilerException;

/**
 * File-system backed template cache.
 *
 * The cache lives inside the project's .lexer/ directory:
 *   .lexer/compiled/{md5(key)}.php  — compiled PHP files
 *   .lexer/ast/{md5(key)}.ast       — serialised AST snapshots
 *
 * Writes are atomic (write to a temp file, then rename) so concurrent
 * requests cannot read a partially-written cache file.
 *
 * The precompiled index (.lexer/compiled/index.php) maps template paths
 * to compiled file paths and is used in production mode to skip all
 * source-level I/O per request.
 */
final class FileCache implements CacheInterface
{
    private bool $indexLoaded    = false;

    /** @var array<string, string> template-path => compiled-path */
    private array $index = [];

    /**
     * @param string $baseDir  Absolute path to the .lexer/ root directory.
     */
    public function __construct(
        private readonly string $baseDir,
    ) {
    }

    // -----------------------------------------------------------------------
    // CacheInterface
    // -----------------------------------------------------------------------

    public function has(string $key): bool
    {
        return file_exists($this->compiledPath($key));
    }

    public function path(string $key): ?string
    {
        $path = $this->compiledPath($key);

        return file_exists($path) ? $path : null;
    }

    public function put(string $key, string $compiledContent): string
    {
        $this->ensureCompiledDir();

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
        // Clear compiled PHP files
        $compiledDir = $this->compiledDir();
        if (is_dir($compiledDir)) {
            $files = glob($compiledDir . DIRECTORY_SEPARATOR . '*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }

        // Clear serialised AST files
        $astDir = $this->astDir();
        if (is_dir($astDir)) {
            $files = glob($astDir . DIRECTORY_SEPARATOR . '*.ast');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }

        $this->index       = [];
        $this->indexLoaded = false;
    }

    public function getDirectory(): string
    {
        return $this->baseDir;
    }

    /**
     * Forget the compiled PHP and AST files for a template given its absolute path.
     *
     * Reconstructs the cache key from the template's current filemtime (the same
     * key that was used when the template was last compiled) and deletes the
     * corresponding .php and .ast cache files.
     *
     * No-op if the file does not exist on disk or has no matching cache entries.
     */
    public function forgetCompiledByPath(string $templateAbsPath): void
    {
        $mtime = @filemtime($templateAbsPath);

        if ($mtime === false) {
            return;
        }

        $key = md5($templateAbsPath . ':' . $mtime);

        $this->forget($key);

        $astPath = $this->astPath($key);

        if (file_exists($astPath)) {
            @unlink($astPath);
        }
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

    /**
     * Return the absolute path where the serialised AST for $key is stored.
     * Location: .lexer/ast/{md5(key)}.ast
     */
    public function astPath(string $key): string
    {
        return $this->astDir() . DIRECTORY_SEPARATOR . md5($key) . '.ast';
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /** Absolute path to the .lexer/compiled/ subdirectory. */
    private function compiledDir(): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . 'compiled';
    }

    /** Absolute path to the .lexer/ast/ subdirectory. */
    private function astDir(): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . 'ast';
    }

    private function compiledPath(string $key): string
    {
        return $this->compiledDir() . DIRECTORY_SEPARATOR . md5($key) . '.php';
    }

    private function ensureCompiledDir(): void
    {
        $dir = $this->compiledDir();

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw CompilerException::cacheDirectoryNotWritable($dir);
            }
        }

        if (!is_writable($dir)) {
            throw CompilerException::cacheDirectoryNotWritable($dir);
        }
    }

    private function indexPath(): string
    {
        return $this->compiledDir() . DIRECTORY_SEPARATOR . 'index.php';
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
        $this->ensureCompiledDir();

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
