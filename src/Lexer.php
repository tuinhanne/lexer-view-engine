<?php

declare(strict_types=1);

namespace Wik\Lexer;

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
 * The engine is framework-agnostic.  Integrate it with any PHP project by
 * simply calling render() with the template name and data.
 */
final class Lexer
{
    private DirectiveRegistry $registry;
    private SectionManager $sectionManager;
    private ComponentManager $componentManager;
    private ?ViewEngine $engine = null;

    private array $viewPaths       = [];
    private ?string $cachePath     = null;
    private string $extension      = LexConfig::DEFAULT_EXTENSION;
    private bool $production       = false;
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
     * @param  string $startDir  Directory to start searching from (default: cwd)
     * @throws \Wik\Lexer\Exceptions\LexException  If no lex.config.json is found
     */
    public static function fromConfig(string $startDir = ''): static
    {
        $config = LexConfig::load($startDir);

        $lexer = new static();
        $lexer->paths($config->viewPaths);
        $lexer->cache($config->cache);
        $lexer->extension($config->extension);

        if ($config->production) {
            $lexer->setProduction();
        }

        if ($config->sandbox) {
            $lexer->enableSandbox();
        }

        foreach ($config->componentPaths as $path) {
            $lexer->componentPath($path);
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
     * Add a single directory to the view search path.
     */
    public function addPath(string $path): static
    {
        $this->viewPaths[] = $path;
        $this->engine      = null;

        return $this;
    }

    /**
     * Set the directory where compiled PHP files and AST caches are stored.
     */
    public function cache(string $path): static
    {
        $this->cachePath = $path;
        $this->engine    = null;

        return $this;
    }

    /**
     * Override the default file extension (default: LexConfig::DEFAULT_EXTENSION).
     */
    public function extension(string $ext): static
    {
        $this->extension = ltrim($ext, '.');
        $this->engine    = null;

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
     * Add a directory to search for component files.
     *
     * Components are located by converting the tag name to kebab-case:
     *   <UserProfile /> → {dir}/user-profile.lex  (also tries UserProfile.lex)
     */
    public function componentPath(string $path): static
    {
        $this->componentManager->addComponentPath($path);

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
     * (cache/index.php) and skips source-level file checks on every request.
     * Enable this after a warm-up compile step in your deployment pipeline.
     */
    public function setProduction(bool $production = true): static
    {
        $this->production = $production;
        $this->engine     = null;

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
     * @param  string               $name  Template name (dot notation supported)
     * @param  array<string, mixed> $data  Variables made available in the template
     *
     * @throws ViewException  if view paths or cache path are not configured,
     *                        or if the template cannot be found
     */
    public function render(string $name, array $data = []): string
    {
        return $this->getEngine()->render($name, $data);
    }

    /**
     * Render a template by its absolute file path and return HTML.
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

    private function buildEngine(): ViewEngine
    {
        if (empty($this->viewPaths)) {
            throw ViewException::noViewPaths();
        }

        $compiler = $this->buildCompiler();

        // FileLoader is the single source of truth for path resolution and
        // mtime-based cache keys — ViewEngine delegates all lookups to it.
        $loader = new FileLoader($this->viewPaths, $this->extension);

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

    private function buildCompiler(): Compiler
    {
        if ($this->cachePath === null) {
            throw ViewException::noCacheDirectory();
        }

        return new Compiler(
            $this->registry,
            new FileCache($this->cachePath),
            $this->production,
            $this->sandboxConfig,
            new OptimizePass(),
        );
    }
}
