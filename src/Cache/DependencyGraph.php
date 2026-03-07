<?php

declare(strict_types=1);

namespace Wik\Lexer\Cache;

/**
 * Tracks template dependency relationships for efficient cache invalidation.
 *
 * When a template is compiled, its static dependencies (extends, includes,
 * component tags) are recorded here together with each dependency's current
 * modification time.
 *
 * Before reusing a cached compiled template, the engine checks whether any
 * recorded dependency has been modified since it was last compiled.  If so,
 * the compiled cache is cleared and the template is recompiled from source.
 *
 * The graph also supports reverse lookups:  given a dependency file, return
 * every template that imports it — useful for tooling and warm-up scripts.
 *
 * Persists to: {cacheDir}/view_dependencies.json
 *
 * On-disk format (pretty-printed JSON):
 * {
 *   "/abs/path/home.lex": {
 *     "/abs/path/layout.lex": 1712000000,
 *     "/abs/path/partials/header.lex": 1712000001
 *   }
 * }
 *
 * Keys are absolute template paths; nested keys are absolute dependency paths;
 * values are Unix timestamps (filemtime) recorded at compilation time.
 */
final class DependencyGraph
{
    private bool $loaded = false;

    /**
     * Forward map: templateAbsPath → { depAbsPath => mtime }
     *
     * @var array<string, array<string, int>>
     */
    private array $forward = [];

    public function __construct(
        private readonly string $cacheDir,
    ) {
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Record that $templatePath depends on the given dependency paths.
     *
     * Each entry in $depsWithMtimes maps an absolute dependency path to the
     * filemtime of that file at the time of compilation.  Subsequent calls
     * for the same $templatePath fully replace the previous entry.
     *
     * @param array<string, int> $depsWithMtimes  [absDepPath => mtime, ...]
     */
    public function record(string $templatePath, array $depsWithMtimes): void
    {
        $this->load();
        $this->forward[$templatePath] = $depsWithMtimes;
        $this->save();
    }

    /**
     * Return true if at least one recorded dependency of $templatePath has a
     * different filemtime than when the template was last compiled, indicating
     * that the template's compiled cache is stale.
     *
     * Returns false when no dependency data is recorded for the template.
     */
    public function isStale(string $templatePath): bool
    {
        $this->load();

        $deps = $this->forward[$templatePath] ?? [];

        foreach ($deps as $depPath => $recordedMtime) {
            $currentMtime = @filemtime($depPath);

            if ($currentMtime !== false && $currentMtime !== $recordedMtime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return all template paths that list $depPath as a dependency.
     *
     * Used for reverse lookups: "who depends on this file?"
     *
     * @return string[]
     */
    public function getDependents(string $depPath): array
    {
        $this->load();

        $dependents = [];

        foreach ($this->forward as $templatePath => $deps) {
            if (isset($deps[$depPath])) {
                $dependents[] = $templatePath;
            }
        }

        return $dependents;
    }

    /**
     * Return the recorded dependency map for $templatePath (absPath → mtime).
     *
     * @return array<string, int>
     */
    public function getDeps(string $templatePath): array
    {
        $this->load();

        return $this->forward[$templatePath] ?? [];
    }

    /**
     * Return the full forward dependency map (templatePath → deps).
     *
     * Primarily useful for CLI tooling and inspection.
     *
     * @return array<string, array<string, int>>
     */
    public function all(): array
    {
        $this->load();

        return $this->forward;
    }

    /**
     * Remove dependency tracking data for $templatePath.
     */
    public function forget(string $templatePath): void
    {
        $this->load();
        unset($this->forward[$templatePath]);
        $this->save();
    }

    /**
     * Clear all recorded dependencies from memory and disk.
     */
    public function flush(): void
    {
        $this->forward = [];
        $this->loaded  = true;
        $this->save();
    }

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    private function graphPath(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'view_dependencies.json';
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $path         = $this->graphPath();

        if (!file_exists($path)) {
            return;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return;
        }

        $this->forward = $data;
    }

    private function save(): void
    {
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                return;
            }
        }

        $json = json_encode($this->forward, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $tmp = $this->graphPath() . '.tmp.' . getmypid();

        if (file_put_contents($tmp, $json, LOCK_EX) !== false) {
            rename($tmp, $this->graphPath());
        }
    }
}
