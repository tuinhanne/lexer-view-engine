<?php

declare(strict_types=1);

namespace Wik\Lexer\Engine;

use Wik\Lexer\Compiler\Compiler;
use Wik\Lexer\Contracts\EscaperInterface;
use Wik\Lexer\Contracts\LoaderInterface;
use Wik\Lexer\Exceptions\ViewException;
use Wik\Lexer\Runtime\ComponentManager;
use Wik\Lexer\Runtime\SectionManager;

/**
 * The primary rendering engine.
 *
 * Resolves template names to file paths via a LoaderInterface, compiles them
 * through the Compiler, and executes the compiled PHP files in an isolated scope.
 *
 * View / cache paths come from the injected LoaderInterface — typically a
 * FileLoader built from lex.config.json by the Lexer entry point.
 *
 * Layout resolution:
 *   After executing a child template, if $__env->hasLayout() is true the engine
 *   re-renders the named layout using the SAME Environment object so that all
 *   captured sections remain available for #yield directives.
 *
 * Component rendering:
 *   ComponentManager is wired up with a view-renderer closure that calls back
 *   into this engine, allowing components to be full .lex templates themselves.
 *
 * Include rendering:
 *   Each included partial gets its own SectionManager so its sections do not
 *   interfere with the parent layout.
 *
 * Cache key strategy:
 *   Template file paths + mtime are used as cache keys (via LoaderInterface::getCacheKey).
 *   This avoids reading full file content on every request just to compute a hash.
 *   For absolute-path renders (renderFile / components / layouts), the mtime is
 *   computed directly from the resolved path.
 */
final class ViewEngine
{
    /** Reusable closure wired into every Environment for #include handling */
    private \Closure $includeRenderer;

    public function __construct(
        private readonly Compiler $compiler,
        private readonly SectionManager $sectionManager,
        private readonly ComponentManager $componentManager,
        private readonly LoaderInterface $loader,
        private readonly ?EscaperInterface $escaper = null,
    ) {
        // Wire up the component renderer so components can use the engine
        $this->componentManager->setViewRenderer(
            function (string $filePath, array $data): string {
                return $this->renderFile($filePath, $data);
            }
        );

        // Build the include renderer once; it is passed to every Environment
        $this->includeRenderer = function (string|array $name, array $data, string $method): string {
            return $this->doInclude($name, $data, $method);
        };
    }

    // -----------------------------------------------------------------------
    // Tooling access
    // -----------------------------------------------------------------------

    /**
     * Expose the compiler so external tooling (wik/lex-debug) can register
     * hooks on the FileCache without creating a second compiler instance.
     */
    public function getCompiler(): Compiler
    {
        return $this->compiler;
    }

    // -----------------------------------------------------------------------
    // Rendering API
    // -----------------------------------------------------------------------

    /**
     * Render a named template with the given data and return HTML.
     *
     * Template names support dot notation: 'layouts.main' → '{dir}/layouts/main.lex'
     *
     * @param  array<string, mixed> $data  Variables extracted into template scope
     * @throws ViewException  if the template cannot be found
     */
    public function render(string $name, array $data = []): string
    {
        $path = $this->resolveName($name);

        // Fresh SectionManager per top-level render so sections don't bleed across requests
        $this->sectionManager->reset();

        $env = $this->makeEnvironment();

        return $this->executeTemplate($path, $data, $env);
    }

    /**
     * Render an absolute file path directly (used by components and layouts).
     *
     * @param  array<string, mixed> $data
     */
    public function renderFile(string $filePath, array $data = []): string
    {
        if (!file_exists($filePath)) {
            throw ViewException::templateNotFound($filePath, [$filePath]);
        }

        $env = $this->makeEnvironment();

        return $this->executeTemplate($filePath, $data, $env);
    }

    // -----------------------------------------------------------------------
    // Execution helpers
    // -----------------------------------------------------------------------

    /**
     * Compile (if needed) and execute a template file.
     *
     * Uses an mtime-based cache key so cache invalidation does not require
     * reading the full file content on every request.
     *
     * Handles layout inheritance: if the template calls #extends, this method
     * renders the parent layout using the same $env after child execution.
     */
    private function executeTemplate(string $filePath, array $data, Environment $env): string
    {
        // Track each template in the layout chain to detect infinite loops.
        // addToLayoutChain() throws TemplateRuntimeException on a duplicate path.
        $env->addToLayoutChain($filePath);

        // Save and restore so nested component renders (inside a #section block)
        // don't overwrite the parent template's currentFile in SectionManager.
        $previousFile = $this->sectionManager->getCurrentFile();
        $this->sectionManager->setCurrentFile($filePath);

        $source       = file_get_contents($filePath);
        $cacheKey     = $this->computeCacheKey($filePath);
        $compiledPath = $this->compiler->compile($source, $filePath, $cacheKey);

        ob_start();

        try {
            $this->includeCompiled($compiledPath, $data, $env);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->sectionManager->setCurrentFile($previousFile);
            throw $e;
        }

        $output = ob_get_clean() ?: '';
        $this->sectionManager->setCurrentFile($previousFile);

        // Layout inheritance: render the parent layout with sections already captured
        if ($env->hasLayout()) {
            $layoutName = $env->consumeLayout();
            $layoutPath = $this->resolveName($layoutName);

            return $this->executeTemplate($layoutPath, $data, $env);
        }

        return $output;
    }

    /**
     * Include a compiled PHP file in an isolated scope.
     *
     * Variables from $data are extracted into the local scope and $__env is set
     * to the current Environment.  EXTR_SKIP prevents template data from
     * overwriting reserved names ($__compiledPath, $__data, $__env).
     *
     * @param array<string, mixed> $data
     */
    private function includeCompiled(string $__compiledPath, array $__data, Environment $__env): void
    {
        extract($__data, EXTR_SKIP);

        /** @noinspection PhpIncludeInspection */
        include $__compiledPath;
    }

    // -----------------------------------------------------------------------
    // Include handling (#include / #includeIf / #includeWhen / #includeFirst)
    // -----------------------------------------------------------------------

    /**
     * Dispatch an include call from the include renderer closure.
     *
     * @param string|string[] $name
     * @param array<string, mixed> $data
     */
    private function doInclude(string|array $name, array $data, string $method): string
    {
        if ($method === 'includeFirst') {
            foreach ((array) $name as $candidate) {
                try {
                    return $this->renderInclude($candidate, $data);
                } catch (\Throwable) {
                    continue;
                }
            }

            return '';
        }

        if ($method === 'includeIf') {
            if ($this->loader->getPath((string) $name) === null) {
                return '';
            }
        }

        return $this->renderInclude((string) $name, $data);
    }

    /**
     * Render a template as an isolated include (own SectionManager).
     *
     * Included partials get their own section scope so they cannot accidentally
     * fill sections in the parent layout.
     *
     * @param array<string, mixed> $data
     */
    private function renderInclude(string $name, array $data): string
    {
        $path         = $this->resolveName($name);
        $source       = file_get_contents($path);
        $cacheKey     = $this->computeCacheKey($path);
        $compiledPath = $this->compiler->compile($source, $path, $cacheKey);

        $sectionManager = new SectionManager();
        $env            = new Environment($sectionManager, $this->componentManager, $this->escaper, $this->includeRenderer);

        ob_start();

        try {
            $this->includeCompiled($compiledPath, $data, $env);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $output = ob_get_clean() ?: '';

        // If the included template itself extends a layout, resolve it
        if ($env->hasLayout()) {
            $layoutName = $env->consumeLayout();
            $layoutPath = $this->resolveName($layoutName);

            return $this->executeTemplate($layoutPath, $data, $env);
        }

        return $output;
    }

    // -----------------------------------------------------------------------
    // Factory helpers
    // -----------------------------------------------------------------------

    /**
     * Create a new Environment wired with the shared include renderer.
     */
    private function makeEnvironment(): Environment
    {
        return new Environment(
            $this->sectionManager,
            $this->componentManager,
            $this->escaper,
            $this->includeRenderer,
        );
    }

    // -----------------------------------------------------------------------
    // Path resolution
    // -----------------------------------------------------------------------

    /**
     * Resolve a template name or absolute path to an absolute file path.
     *
     * Delegates to the injected LoaderInterface, which handles dot notation,
     * directory scanning, and absolute-path pass-through.
     *
     * @throws ViewException  if no matching file is found
     */
    public function resolveName(string $name): string
    {
        $path = $this->loader->getPath($name);

        if ($path !== null) {
            return $path;
        }

        // loader->load() throws ViewException with proper search-path details
        $this->loader->load($name);

        return ''; // unreachable — load() always throws when getPath() returns null
    }

    // -----------------------------------------------------------------------
    // Cache key helpers
    // -----------------------------------------------------------------------

    /**
     * Compute a mtime-based cache key for the given absolute file path.
     *
     * Using path + mtime instead of file content means we avoid reading the
     * entire file just to derive a hash on every request.  When the file
     * modification time changes the key changes and the cache is invalidated.
     */
    private function computeCacheKey(string $filePath): string
    {
        $mtime = @filemtime($filePath);

        return md5($filePath . ':' . ($mtime !== false ? $mtime : '0'));
    }
}
