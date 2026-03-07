<?php

declare(strict_types=1);

namespace Wik\Lexer\Config;

use Wik\Lexer\Exceptions\LexException;

/**
 * LexConfig – reads and validates lex.config.json from the project root.
 *
 * The file is looked up by walking up from the given start directory until
 * a lex.config.json or the filesystem root is found (same strategy as
 * tools like ESLint, Prettier, etc.).
 *
 * Supported fields
 * ────────────────
 * viewPaths        string[]   Directories to search for .lex templates
 * production       bool       Enable production / index-build mode
 * sandbox          bool       Enable sandbox mode
 * directivesFile   string?    PHP file that registers custom directives
 *                             (relative to project root, or absolute)
 *
 * Note: the cache directory is fixed at {projectRoot}/.lexer/ and cannot
 * be customised in the config file.
 *
 * All path values may be relative (resolved against the config file's
 * own directory) or absolute.
 *
 * Example lex.config.json
 * ───────────────────────
 * {
 *   "viewPaths":      ["views", "resources/views"],
 *   "directivesFile": "config/lex-directives.php",
 *   "production":     false,
 *   "sandbox":        false
 * }
 *
 * Directives file format (config/lex-directives.php)
 * ───────────────────────────────────────────────────
 * The file must return a callable with signature:
 *   function (\Wik\Lexer\Lexer $lexer): void
 *
 * Example:
 *   <?php
 *   use Wik\Lexer\Lexer;
 *   return static function (Lexer $lexer): void {
 *       $lexer->directive('money',    fn($e) => "<?php echo number_format({$e}, 2); ?>");
 *       $lexer->directive('datetime', fn($e) => "<?php echo date('d/m/Y H:i', {$e}); ?>");
 *   };
 *
 * Auto-discovery: if "directivesFile" is not set, the engine automatically
 * checks for lex.directives.php at the project root.
 */
final class LexConfig
{
    public const FILE_NAME = 'lex.config.json';

    /** Default template file extension (used when not specified in config or CLI) */
    public const DEFAULT_EXTENSION = 'lex';

    /** Default view lookup paths (relative to project root) */
    public const DEFAULT_VIEW_PATHS = ['views'];

    /** Cache root directory name (relative to project root, fixed — not user-configurable) */
    public const CACHE_DIR = '.lexer';

    /**
     * Well-known directives file name.
     * Auto-discovered at the project root when "directivesFile" is not set in config.
     */
    public const DIRECTIVES_FILE = 'lex.directives.php';

    /** @var string[] */
    public readonly array $viewPaths;

    public readonly bool   $production;
    public readonly bool   $sandbox;

    /**
     * Absolute path to the directives file, or null if none is configured
     * and lex.directives.php does not exist at the project root.
     */
    public readonly ?string $directivesFile;

    /** Absolute path of the config file that was loaded */
    public readonly string $configFilePath;

    /** Directory that contains the config file (= project root) */
    public readonly string $projectRoot;

    private function __construct(
        array   $viewPaths,
        bool    $production,
        bool    $sandbox,
        ?string $directivesFile,
        string  $configFilePath,
    ) {
        $this->viewPaths      = $viewPaths;
        $this->production     = $production;
        $this->sandbox        = $sandbox;
        $this->directivesFile = $directivesFile;
        $this->configFilePath = $configFilePath;
        $this->projectRoot    = dirname($configFilePath);
    }

    // ── Loaders ───────────────────────────────────────────────────────────────

    /**
     * Load lex.config.json by searching upward from $startDir.
     *
     * @param  string $startDir  Directory to start searching from (default: cwd)
     * @throws LexException      If no config file is found
     */
    public static function load(string $startDir = ''): self
    {
        $path = self::find($startDir ?: (string) getcwd());

        if ($path === null) {
            throw new LexException(
                'lex.config.json not found. Run "lex init" to create one.'
            );
        }

        return self::loadFrom($path);
    }

    /**
     * Load from an explicit file path.
     *
     * @throws LexException  On invalid JSON or missing required fields
     */
    public static function loadFrom(string $filePath): self
    {
        if (!is_file($filePath)) {
            throw new LexException("Config file not found: {$filePath}");
        }

        $raw = file_get_contents($filePath);

        if ($raw === false) {
            throw new LexException("Cannot read config file: {$filePath}");
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new LexException("Config file is not a JSON object: {$filePath}");
        }

        $dir = dirname(realpath($filePath) ?: $filePath);

        return new self(
            viewPaths:      self::resolvePaths($data['viewPaths'] ?? self::DEFAULT_VIEW_PATHS, $dir),
            production:     (bool) ($data['production'] ?? false),
            sandbox:        (bool) ($data['sandbox']    ?? false),
            directivesFile: self::resolveDirectivesFile($data['directivesFile'] ?? null, $dir),
            configFilePath: realpath($filePath) ?: $filePath,
        );
    }

    /**
     * Try to load a config file, returning null if none exists (no exception).
     */
    public static function tryLoad(string $startDir = ''): ?self
    {
        try {
            return self::load($startDir);
        } catch (LexException) {
            return null;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Walk up from $dir until lex.config.json is found, or return null.
     */
    public static function find(string $dir): ?string
    {
        $dir = rtrim(realpath($dir) ?: $dir, DIRECTORY_SEPARATOR);

        while (true) {
            $candidate = $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;

            if (is_file($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                // Reached filesystem root
                return null;
            }

            $dir = $parent;
        }
    }

    /**
     * Resolve the directives file path.
     *
     * If $value is given in config, resolve it relative to $base.
     * Otherwise auto-discover lex.directives.php at the project root.
     * Returns null if neither exists.
     */
    private static function resolveDirectivesFile(?string $value, string $base): ?string
    {
        if ($value !== null) {
            $resolved = self::resolvePath($value, $base);

            return is_file($resolved) ? $resolved : null;
        }

        // Auto-discover
        $fallback = $base . DIRECTORY_SEPARATOR . self::DIRECTIVES_FILE;

        return is_file($fallback) ? $fallback : null;
    }

    /**
     * Resolve a list of relative paths against a base directory.
     *
     * @param  mixed  $value
     * @return string[]
     */
    private static function resolvePaths(mixed $value, string $base): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        return array_values(array_map(
            fn(mixed $p) => self::resolvePath((string) $p, $base),
            $value,
        ));
    }

    private static function resolvePath(string $path, string $base): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private static function isAbsolute(string $path): bool
    {
        // Unix absolute, Windows drive letter, or UNC path
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (strlen($path) >= 3 && $path[1] === ':');
    }
}
