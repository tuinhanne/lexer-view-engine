<?php

declare(strict_types=1);

namespace Wik\Lexer;

use Wik\Lexer\Cache\DependencyGraph;
use Wik\Lexer\Cache\FileCache;
use Wik\Lexer\Compiler\Compiler;
use Wik\Lexer\Compiler\OptimizePass;
use Wik\Lexer\Config\LexConfig;
use Wik\Lexer\Contracts\EscaperInterface;
use Wik\Lexer\Engine\ViewEngine;
use Wik\Lexer\Exceptions\ViewException;
use Wik\Lexer\Loader\FileLoader;
use Wik\Lexer\Runtime\ComponentManager;
use Wik\Lexer\Runtime\SectionManager;
use Wik\Lexer\Security\SandboxConfig;
use Wik\Lexer\Support\DirectiveRegistry;

/**
 * Wik/Lexer — Main entry point.
 *
 * This class wires together the full compilation pipeline and exposes a
 * clean, fluent API for configuring and rendering .lex templates.
 *
 * Quick start:
 *
 *   $lex = Lexer::fromConfig();
 *
 *   // Register a custom directive
 *   $lexer->directive('datetime', fn(string $expr) => "<?php echo date('Y-m-d H:i:s', (int)({$expr})); ?>");
 *
 *   // Register a named component
 *   $lexer->component('Alert', __DIR__ . '/views/components/alert.lex');
 *
 *   echo $lexer->render('home', ['title' => 'Welcome']);
 *
 * Cache layout (fixed, not user-configurable):
 *   {projectRoot}/.lexer/compiled/{hash}.php  — compiled PHP files
 *   {projectRoot}/.lexer/ast/{hash}.ast        — serialised AST snapshots
 *
 * The engine is framework-agnostic.  Integrate it with any PHP project by
 * simply calling render() with the template name and data.
 */
final class Lexer
{
    private DirectiveRegistry $registry;
    private SectionManager $sectionManager;
    private ComponentManager $componentManager;
    private ?ViewEngine $engine = null;
    private ?\Wik\Lexer\Debug\LexDebugger $debugger = null;

    private array $viewPaths          = [];
    private string $projectRoot       = '';
    private ?string $cacheDir         = null;
    private bool $production          = false;
    private ?SandboxConfig $sandboxConfig = null;
    private ?EscaperInterface $escaper    = null;

    public function __construct()
    {
        $this->registry         = new DirectiveRegistry();
        $this->sectionManager   = new SectionManager();
        $this->componentManager = new ComponentManager();
    }

    // -----------------------------------------------------------------------
    // Config-file factory
    // -----------------------------------------------------------------------

    /**
     * Create a Lexer pre-configured from a lex.config.json file.
     *
     * The config file is searched upward from $startDir (default: cwd), so
     * you can call this without any arguments from anywhere inside the project:
     *
     *   $lexer = Lexer::fromConfig();
     *   echo $lexer->render('home', ['user' => $user]);
     *
     * You can still chain additional fluent calls after construction:
     *
     *   $lexer = Lexer::fromConfig()
     *       ->directive('money', fn($e) => "<?php echo money_format($e); ?>")
     *       ->setEscaper(new MyEscaper());
     *
     * The .lexer/ cache directory is automatically placed at the project root
     * (the directory containing lex.config.json).
     *
     * @param  string $startDir  Directory to start searching from (default: cwd)
     * @throws \Wik\Lexer\Exceptions\LexException  If no lex.config.json is found
     */
    public static function fromConfig(string $startDir = ''): static
    {
        $config = LexConfig::load($startDir);

        $lexer = new static();
        $lexer->paths($config->viewPaths);
        $lexer->projectRoot = $config->projectRoot;

        if ($config->production) {
            $lexer->setProduction();
        }

        if ($config->sandbox) {
            $lexer->enableSandbox();
        }

        return $lexer;
    }

    // -----------------------------------------------------------------------
    // Fluent configuration API
    // -----------------------------------------------------------------------

    /**
     * Set the directories to search for view templates.
     *
     * @param  string[] $paths  Absolute directory paths
     */
    public function paths(array $paths): static
    {
        $this->viewPaths = $paths;
        $this->engine    = null; // invalidate cached engine

        return $this;
    }

    /**
     * Set an explicit cache directory (overrides the default .lexer/ location).
     */
    public function cache(string $dir): static
    {
        $this->cacheDir = $dir;
        $this->engine   = null;

        return $this;
    }

    /**
     * Add a directory to search for component view files.
     */
    public function componentPath(string $path): static
    {
        $this->componentManager->addComponentPath($path);

        return $this;
    }

    /**
     * Add a single directory to the view search path.
     */
    public function addPath(string $path): static
    {
        $this->viewPaths[] = $path;
        $this->engine      = null;

        return $this;
    }

    /**
     * Register a custom template directive.
     *
     * The handler receives the directive expression (everything inside the
     * parentheses) and must return a PHP string to embed in the compiled file.
     *
     * Example:
     *   $lexer->directive('uppercase', fn($expr) => "<?php echo strtoupper($expr); ?>");
     *
     * Usage in template:
     *   #uppercase($name)
     *
     * @param callable(string): string $handler
     */
    public function directive(string $name, callable $handler): static
    {
        $this->registry->register($name, $handler);

        return $this;
    }

    /**
     * Register a named component mapped to a view file.
     *
     * Example:
     *   $lexer->component('Alert', __DIR__ . '/views/components/alert.lex');
     *
     * Usage in template:
     *   <Alert type="success">Saved!</Alert>
     *
     * @param string $name     Component tag name (PascalCase recommended)
     * @param string $viewPath Absolute path to the component's .lex file
     */
    public function component(string $name, string $viewPath): static
    {
        $this->componentManager->registerComponent($name, $viewPath);

        return $this;
    }

    /**
     * Register a component class that implements a mount() method.
     *
     * The class is instantiated, mount() is called with matching prop names,
     * and all public properties are injected into the component template scope.
     *
     * @param class-string $class
     */
    public function registerComponentClass(string $name, string $class): static
    {
        $this->componentManager->registerComponentClass($name, $class);

        return $this;
    }

    /**
     * Set the PHP namespace prefix used when auto-discovering component classes.
     *
     * Example: setComponentClassNamespace('App\\View\\Components') means
     * a <Card> component will look for App\View\Components\CardComponent.
     */
    public function componentClassNamespace(string $namespace): static
    {
        $this->componentManager->setComponentClassNamespace($namespace);

        return $this;
    }

    /**
     * Enable production mode.
     *
     * In production mode the engine maintains a precompiled view index
     * (.lexer/compiled/index.php) and skips source-level file checks on
     * every request.  Enable this after a warm-up compile step in your
     * deployment pipeline.
     */
    public function setProduction(bool $production = true): static
    {
        $this->production = $production;
        $this->engine     = null;
        $this->debugger   = null; // invalidate — production never uses debugger

        return $this;
    }

    /**
     * Enable sandbox mode with the given config (defaults to SandboxConfig::secure()).
     *
     * In sandbox mode templates are validated against a function whitelist and
     * raw echo ({!! … !!}) is forbidden by default.
     */
    public function enableSandbox(?SandboxConfig $config = null): static
    {
        $this->sandboxConfig = $config ?? SandboxConfig::secure();
        $this->engine        = null;

        return $this;
    }

    /**
     * Set a custom SandboxConfig without fully enabling secure mode.
     */
    public function setSandboxConfig(SandboxConfig $config): static
    {
        $this->sandboxConfig = $config;
        $this->engine        = null;

        return $this;
    }

    /**
     * Override the HTML escaper used for {{ expr }} output.
     *
     * By default HtmlEscaper is used (htmlspecialchars with ENT_QUOTES|ENT_SUBSTITUTE).
     */
    public function setEscaper(EscaperInterface $escaper): static
    {
        $this->escaper = $escaper;
        $this->engine  = null;

        return $this;
    }

    // -----------------------------------------------------------------------
    // Rendering API
    // -----------------------------------------------------------------------

    /**
     * Render a named template and return the resulting HTML string.
     *
     * In development mode (default), output is automatically wrapped by
     * LexDebugger which injects the __lex_debug__ JSON payload for the
     * Chrome DevTools extension.  In production mode (setProduction(true))
     * the debugger is bypassed entirely — zero overhead.
     *
     * @param  string               $name  Template name (dot notation supported)
     * @param  array<string, mixed> $data  Variables made available in the template
     *
     * @throws ViewException  if view paths are not configured or the template
     *                        cannot be found
     */
    public function render(string $name, array $data = []): string
    {
        if (!$this->production) {
            return $this->getDebugger()->render($name, $data);
        }

        return $this->getEngine()->render($name, $data);
    }

    /**
     * Render a template by its absolute file path and return HTML.
     *
     * Debug payload is not injected for file renders (used internally by
     * components and layouts); the top-level render() call covers the full page.
     *
     * @param  array<string, mixed> $data
     */
    public function renderFile(string $filePath, array $data = []): string
    {
        return $this->getEngine()->renderFile($filePath, $data);
    }

    // -----------------------------------------------------------------------
    // Direct compiler access (useful for testing / tooling)
    // -----------------------------------------------------------------------

    /**
     * Return the underlying Compiler instance.
     */
    public function getCompiler(): Compiler
    {
        return $this->buildCompiler();
    }

    /**
     * Return the configured DirectiveRegistry.
     */
    public function getRegistry(): DirectiveRegistry
    {
        return $this->registry;
    }

    /**
     * Return the ComponentManager (for debug hook registration via wik/lex-debug).
     */
    public function getComponentManager(): ComponentManager
    {
        return $this->componentManager;
    }

    /**
     * Return the SectionManager (for debug hook registration via wik/lex-debug).
     */
    public function getSectionManager(): SectionManager
    {
        return $this->sectionManager;
    }

    /**
     * Return the ViewEngine (builds it if not yet constructed).
     */
    public function getEngine(): ViewEngine
    {
        if ($this->engine === null) {
            $this->engine = $this->buildEngine();
        }

        return $this->engine;
    }

    // -----------------------------------------------------------------------
    // Internal factory
    // -----------------------------------------------------------------------

    private function getDebugger(): \Wik\Lexer\Debug\LexDebugger
    {
        if ($this->debugger === null) {
            $this->debugger = new \Wik\Lexer\Debug\LexDebugger($this);
        }

        return $this->debugger;
    }

    private function buildEngine(): ViewEngine
    {
        if (empty($this->viewPaths)) {
            throw ViewException::noViewPaths();
        }

        // FileLoader is the single source of truth for path resolution and
        // mtime-based cache keys — ViewEngine delegates all lookups to it.
        $loader = new FileLoader($this->viewPaths);

        // Pass the loader's path resolver into the compiler so the dependency
        // graph can record resolved absolute paths for each view name found in
        // #extends / #include / component tags.
        $compiler = $this->buildCompiler(fn(string $name): ?string => $loader->getPath($name));

        // Convention: auto-register the `components` subdirectory of every view
        // path so components are discovered without any extra configuration.
        // Explicitly registered paths (via componentPath()) always take priority
        // because they are added first; addComponentPath() deduplicates entries.
        foreach ($this->viewPaths as $viewPath) {
            $this->componentManager->addComponentPath(
                rtrim($viewPath, '/\\') . DIRECTORY_SEPARATOR . 'components'
            );
        }

        return new ViewEngine($compiler, $this->sectionManager, $this->componentManager, $loader, $this->escaper);
    }

    /**
     * @param (callable(string): ?string)|null $depResolver
     *   Maps a view name to its absolute file path.  Supplied by buildEngine()
     *   when constructing the compiler with dependency-graph support.
     *   When null (e.g. getCompiler() called standalone), dep tracking is skipped.
     */
    private function buildCompiler(?callable $depResolver = null): Compiler
    {
        $lexerDir = $this->resolveLexerDir();

        $depGraph = $depResolver !== null
            ? new DependencyGraph($lexerDir)
            : null;

        return new Compiler(
            $this->registry,
            new FileCache($lexerDir),
            $this->production,
            $this->sandboxConfig,
            new OptimizePass(),
            $depGraph,
            $depResolver,
        );
    }

    /**
     * Return the absolute path to the .lexer/ cache root.
     *
     * Defaults to {cwd}/.lexer when no project root has been set
     * (e.g. when using the fluent API without lex.config.json).
     */
    private function resolveLexerDir(): string
    {
        if ($this->cacheDir !== null) {
            return $this->cacheDir;
        }

        $root = $this->projectRoot ?: (string) getcwd();

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . LexConfig::CACHE_DIR;
    }
}
