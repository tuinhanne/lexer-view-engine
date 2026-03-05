<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler;

use Wik\Lexer\Cache\FileCache;
use Wik\Lexer\Compiler\Node\BreakNode;
use Wik\Lexer\Compiler\Node\CheckEmptyNode;
use Wik\Lexer\Compiler\Node\ComponentNode;
use Wik\Lexer\Compiler\Node\ContinueNode;
use Wik\Lexer\Compiler\Node\DirectiveNode;
use Wik\Lexer\Compiler\Node\EchoNode;
use Wik\Lexer\Compiler\Node\ExtendsNode;
use Wik\Lexer\Compiler\Node\ForEachNode;
use Wik\Lexer\Compiler\Node\ForNode;
use Wik\Lexer\Compiler\Node\IfNode;
use Wik\Lexer\Compiler\Node\IncludeNode;
use Wik\Lexer\Compiler\Node\IssetNode;
use Wik\Lexer\Compiler\Node\Node;
use Wik\Lexer\Compiler\Node\ParentNode;
use Wik\Lexer\Compiler\Node\PhpNode;
use Wik\Lexer\Compiler\Node\PushNode;
use Wik\Lexer\Compiler\Node\SectionNode;
use Wik\Lexer\Compiler\Node\StackNode;
use Wik\Lexer\Compiler\Node\SwitchNode;
use Wik\Lexer\Compiler\Node\TextNode;
use Wik\Lexer\Compiler\Node\UnlessNode;
use Wik\Lexer\Compiler\Node\WhileNode;
use Wik\Lexer\Compiler\Node\YieldNode;
use Wik\Lexer\Security\ExpressionValidator;
use Wik\Lexer\Security\SandboxConfig;
use Wik\Lexer\Support\DirectiveRegistry;

/**
 * Orchestrates the full compilation pipeline:
 *   source string → tokens → AST → (validate) → (optimize) → compiled PHP file
 *
 * Caching strategy:
 *   - A cache key identifies each compiled file.  By default the key is the
 *     raw source string (md5'd by FileCache), but callers may supply an explicit
 *     $cacheKey — e.g. a path+mtime hash from FileLoader::getCacheKey() — to
 *     avoid hashing the full source on every request.
 *   - Compiled PHP is stored as  {cacheDir}/{md5(key)}.php
 *   - Serialised AST is stored as {cacheDir}/{md5(key)}.ast
 *   - If igbinary is available it is used for faster, smaller AST serialisation.
 *   - In production mode, a precompiled view index (cache/index.php) is
 *     maintained so source-level file lookups can be skipped on every request.
 *
 * Validation & optimisation:
 *   - When a SandboxConfig is provided, an AstValidator enforces sandbox rules
 *     (raw echo forbidden, function whitelist, structural constraints).
 *   - When an OptimizePass is provided, it merges adjacent TextNodes and strips
 *     empty whitespace-only nodes before code generation.
 */
final class Compiler
{
    private readonly Lexer $lexer;
    private readonly Parser $parser;

    /**
     * Allowed node classes for safe PHP unserialize() when igbinary is unavailable.
     *
     * @var class-string[]
     */
    private const ALLOWED_AST_CLASSES = [
        TextNode::class,
        EchoNode::class,
        IfNode::class,
        ForEachNode::class,
        ForNode::class,
        WhileNode::class,
        UnlessNode::class,
        IssetNode::class,
        CheckEmptyNode::class,
        SwitchNode::class,
        BreakNode::class,
        ContinueNode::class,
        SectionNode::class,
        ExtendsNode::class,
        YieldNode::class,
        ComponentNode::class,
        DirectiveNode::class,
        PushNode::class,
        StackNode::class,
        ParentNode::class,
        IncludeNode::class,
        PhpNode::class,
    ];

    public function __construct(
        private readonly DirectiveRegistry $registry,
        private readonly FileCache $cache,
        private readonly bool $production = false,
        private readonly ?SandboxConfig $sandboxConfig = null,
        private readonly ?OptimizePass $optimizer = null,
    ) {
        $this->lexer  = new Lexer();
        $this->parser = new Parser($registry);
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Compile a template source string and return the path to the compiled PHP file.
     *
     * The compiled file can be safely include()'d at render time.
     *
     * @param string $source       Raw template source (used when cache misses)
     * @param string $templatePath Absolute source file path (recorded in headers / index)
     * @param string $cacheKey     Optional explicit cache key.  When provided it is used
     *                             instead of $source for all cache lookups and filenames.
     *                             Pass a path+mtime hash (e.g. from FileLoader::getCacheKey)
     *                             to avoid hashing the full source on every request.
     *
     * Pipeline:
     *   1. In production mode: check the precompiled view index first.
     *   2. If compiled PHP is already cached for this key, return it immediately.
     *   3. If only the serialised AST exists, re-generate PHP from the AST.
     *   4. Otherwise run the full pipeline: lex → parse → validate → optimize.
     */
    public function compile(string $source, string $templatePath, string $cacheKey = ''): string
    {
        // An explicit key takes priority; fall back to the source string itself
        $key = $cacheKey !== '' ? $cacheKey : $source;

        // Production mode: check precompiled index first (no source-level I/O)
        if ($this->production) {
            $indexed = $this->cache->indexLookup($templatePath);
            if ($indexed !== null) {
                return $indexed;
            }
        }

        // Compiled PHP already up-to-date for this key
        if ($this->cache->has($key)) {
            $compiledPath = (string) $this->cache->path($key);

            if ($this->production) {
                $this->cache->indexRegister($templatePath, $compiledPath);
            }

            return $compiledPath;
        }

        $astPath = $this->cache->astPath($key);

        // AST cached but PHP not yet generated (e.g. after an optimizer or codegen change)
        if (file_exists($astPath)) {
            $nodes = $this->loadAst($astPath);

            if ($nodes !== null) {
                if ($this->optimizer !== null) {
                    $nodes = $this->optimizer->optimize($nodes);
                }

                $compiledPath = $this->writeFile($key, $this->nodesToPhp($nodes), $templatePath);

                if ($this->production) {
                    $this->cache->indexRegister($templatePath, $compiledPath);
                }

                return $compiledPath;
            }
        }

        // Full pipeline: lex → parse → (validate) → (optimize) → write
        $tokens = $this->lexer->tokenize($source);
        $nodes  = $this->parser->parse($tokens);

        // AST validation (sandbox mode / structural constraints)
        if ($this->sandboxConfig !== null) {
            $validator = new AstValidator(
                $this->sandboxConfig,
                new ExpressionValidator($this->sandboxConfig),
                $templatePath,
            );
            $validator->validate($nodes);
        }

        // Optimisation pass
        if ($this->optimizer !== null) {
            $nodes = $this->optimizer->optimize($nodes);
        }

        $this->saveAst($astPath, $nodes);

        $compiledPath = $this->writeFile($key, $this->nodesToPhp($nodes), $templatePath);

        if ($this->production) {
            $this->cache->indexRegister($templatePath, $compiledPath);
        }

        return $compiledPath;
    }

    /**
     * Force a full recompile regardless of existing caches.
     *
     * @param string $cacheKey  Must match the key used in the original compile() call.
     */
    public function recompile(string $source, string $templatePath, string $cacheKey = ''): string
    {
        $key = $cacheKey !== '' ? $cacheKey : $source;

        $this->cache->forget($key);

        $astPath = $this->cache->astPath($key);
        if (file_exists($astPath)) {
            @unlink($astPath);
        }

        return $this->compile($source, $templatePath, $cacheKey);
    }

    /**
     * Parse a source string and return the AST node array (no caching).
     * Useful for testing and tooling.
     *
     * @return Node[]
     */
    public function parse(string $source): array
    {
        $tokens = $this->lexer->tokenize($source);

        return $this->parser->parse($tokens);
    }

    /**
     * Tokenise a source string and return the Token array (no caching).
     * Useful for testing and tooling.
     *
     * @return Token[]
     */
    public function tokenize(string $source): array
    {
        return $this->lexer->tokenize($source);
    }

    // -----------------------------------------------------------------------
    // PHP generation
    // -----------------------------------------------------------------------

    /**
     * Convert an AST node array into a PHP source string.
     *
     * @param Node[] $nodes
     */
    private function nodesToPhp(array $nodes): string
    {
        $body = '';

        foreach ($nodes as $node) {
            $body .= $node->compile();
        }

        return $body;
    }

    // -----------------------------------------------------------------------
    // Cache management
    // -----------------------------------------------------------------------

    /**
     * Build the PHP file header + body and persist via FileCache (atomic write).
     *
     * @param Node[] $nodes
     */
    private function writeFile(string $source, string $content, string $templatePath): string
    {
        // declare(strict_types=1) MUST be the first PHP statement in the file
        $header  = "<?php declare(strict_types=1);\n";
        $header .= "// Compiled by Wik/Lexer — do not edit this file.\n";
        $header .= "// Source: {$templatePath}\n";
        $header .= "// Compiled: " . date('Y-m-d H:i:s') . "\n";
        $header .= "?>\n";

        return $this->cache->put($source, $header . $content);
    }

    // -----------------------------------------------------------------------
    // AST serialisation helpers
    // -----------------------------------------------------------------------

    /**
     * Serialise an AST node array to disk.
     *
     * Uses igbinary when available for smaller, faster serialisation.
     * Falls back to PHP's native serialize() otherwise.
     *
     * @param Node[] $nodes
     */
    private function saveAst(string $path, array $nodes): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $data = function_exists('igbinary_serialize')
            ? igbinary_serialize($nodes)
            : serialize($nodes);

        $this->atomicWrite($path, $data);
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

    /**
     * Deserialise an AST node array from disk.
     *
     * Returns null if the file is invalid or cannot be deserialised safely.
     *
     * When igbinary is available it is used (binary format, immune to PHP
     * object-injection attacks).  Otherwise PHP's native unserialize() is
     * called with an explicit allowed_classes allowlist to prevent gadget chains.
     *
     * @return Node[]|null
     */
    private function loadAst(string $path): ?array
    {
        $data = @file_get_contents($path);

        if ($data === false) {
            return null;
        }

        if (function_exists('igbinary_unserialize')) {
            $result = @igbinary_unserialize($data);
        } else {
            $result = @unserialize($data, ['allowed_classes' => self::ALLOWED_AST_CLASSES]);
        }

        if (!\is_array($result)) {
            return null;
        }

        return $result;
    }
}
