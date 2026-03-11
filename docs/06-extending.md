# Extending the Package

## Architecture Overview

```
Lexer (entry point — fluent API + Lexer::fromConfig() factory)
 ├── Config
 │    └── LexConfig        reads / validates lex.config.json (walk-up search)
 ├── Compiler
 │    ├── Lexer            tokenises source into Token[]
 │    ├── Parser           builds AST (Node[]) from Token[]
 │    ├── AstValidator     structural + sandbox validation pass
 │    ├── OptimizePass     merge TextNodes, strip empty nodes
 │    └── Node/*           each node compiles itself to a PHP string
 ├── Cache
 │    └── FileCache        atomic PHP cache + precompiled index
 ├── Security
 │    ├── SandboxConfig    immutable sandbox policy (functions, raw echo)
 │    ├── HtmlEscaper      default EscaperInterface implementation
 │    └── ExpressionValidator  regex-based function call analysis
 ├── Engine
 │    ├── ViewEngine       resolves, compiles, renders, handles layout chain
 │    └── Environment      runtime $__env in compiled templates
 └── Runtime
      ├── SectionManager   ob-based section + push-stack capture
      └── ComponentManager named slots, component classes, recursion guard
```

---

## Adding New Built-in Directives

### 1. Create the Node class

```php
// src/Compiler/Node/RawPhpNode.php
namespace Wik\Lexer\Compiler\Node;

final class RawPhpNode extends Node
{
    public function __construct(
        public readonly string $code,
    ) {}

    public function compile(): string
    {
        return '<?php ' . $this->code . ' ?>';
    }
}
```

### 2. Register the directive in the Parser

In [Parser.php](../src/Compiler/Parser.php), add the name to `BUILT_IN`:

```php
private const BUILT_IN = [
    // ...existing...
    'php', 'endphp',
];
```

Add a case in `processDirective()`:

```php
'php'    => $this->handleRawPhp($token),
'endphp' => $this->handleEndRawPhp($token),
```

Add the handler methods:

```php
private function handleRawPhp(Token $token): void
{
    $this->stack[] = [
        'type'     => 'php',
        'children' => [],
        'extras'   => ['line' => $token->line],
    ];
}

private function handleEndRawPhp(Token $token): void
{
    $this->requireTopFrame('php', $token);
    $frame = array_pop($this->stack);
    // collect children as raw PHP
    $code = '';
    foreach ($frame['children'] as $child) {
        $code .= $child->compile();
    }
    $this->addNode(new RawPhpNode($code));
}
```

### 3. Update the AST unserialise allowlist in the Compiler

In [Compiler.php](../src/Compiler/Compiler.php), add the new class to `ALLOWED_AST_CLASSES`:

```php
private const ALLOWED_AST_CLASSES = [
    // ...existing...
    \Wik\Lexer\Compiler\Node\RawPhpNode::class,
];
```

---

## Adding New Runtime Features to Environment

The `$__env` object (an instance of `Environment`) is available in every compiled template.
To expose new helpers, extend `Environment` or decorate it:

```php
namespace App\View;

use Wik\Lexer\Engine\Environment;

final class AppEnvironment extends Environment
{
    public function route(string $name, array $params = []): string
    {
        return app('router')->generate($name, $params);
    }

    public function asset(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
}
```

Then inject it by extending `ViewEngine`:

```php
use Wik\Lexer\Engine\ViewEngine;
use Wik\Lexer\Runtime\{SectionManager, ComponentManager};

final class AppViewEngine extends ViewEngine
{
    protected function makeEnvironment(): AppEnvironment
    {
        return new AppEnvironment($this->sectionManager, $this->componentManager);
    }
}
```

Templates can then call:

```
<a href="{{ $__env->route('user.profile', ['id' => $user->id]) }}">Profile</a>
<img src="{{ $__env->asset('images/logo.png') }}" />
```

---

## Custom Component Resolvers

By default components are resolved from files. You can add a custom resolver
at the `ComponentManager` level by extending it:

```php
use Wik\Lexer\Runtime\ComponentManager;

final class AppComponentManager extends ComponentManager
{
    protected function resolveComponentPath(string $name): string
    {
        // Check a class-based component registry first
        if ($class = $this->classComponentRegistry[$name] ?? null) {
            return $class->viewPath();
        }

        // Fall back to default file resolution
        return parent::resolveComponentPath($name);
    }
}
```

---

## Writing Tests for Custom Directives

```php
use PHPUnit\Framework\TestCase;
use Wik\Lexer\Cache\FileCache;
use Wik\Lexer\Compiler\Compiler;
use Wik\Lexer\Support\DirectiveRegistry;

final class MyDirectiveTest extends TestCase
{
    public function testUppercaseDirectiveOutput(): void
    {
        $registry = new DirectiveRegistry();
        $registry->register('upper', fn($e) => "<?php echo strtoupper((string)({$e})); ?>");

        $compiler = new Compiler($registry, new FileCache(sys_get_temp_dir() . '/lexer_test_' . uniqid()));
        $nodes    = $compiler->parse('#upper($name)');

        $compiled = '';
        foreach ($nodes as $node) {
            $compiled .= $node->compile();
        }

        $this->assertStringContainsString('strtoupper', $compiled);
    }
}
```

---

## Clearing the Cache Programmatically

After modifying any of the following, clear the `cache/` directory to force recompilation:

- Template `.lex` files
- Custom directive handlers
- Any Node class's `compile()` method

Via PHP:

```php
foreach (glob($cacheDir . '/*.{php,ast}', GLOB_BRACE) as $file) {
    unlink($file);
}
```

---

---

## Security / Sandbox Mode

### Enabling sandbox mode

```php
// Secure defaults: no raw echo, no function calls, no custom directives
$lexer->enableSandbox();

// Or with a custom config
$lexer->enableSandbox(
    SandboxConfig::secure()
        ->withAllowedFunctions(['date', 'number_format', 'strtoupper', 'strtolower'])
        ->withRawEcho(false)
);
```

### SandboxConfig options

| Method | Default (secure) | Description |
|---|---|---|
| `withRawEcho(bool)` | `false` | Allow / forbid `{!! … !!}` |
| `withAllowedFunctions(array)` | `[]` (none) | Whitelist of callable PHP functions |
| `withAllowedDirectives(array)` | `[]` (none) | Whitelist of custom directives |
| `withCustomDirectives(bool)` | `false` | Allow any custom directive |

`null` means **no restriction** (permissive). `[]` means **nothing allowed**.

### Custom escaper

Replace `htmlspecialchars` with your own escaping logic:

```php
use Wik\Lexer\Contracts\EscaperInterface;

final class MyEscaper implements EscaperInterface
{
    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$lexer->setEscaper(new MyEscaper());
```

All `{{ expr }}` output is then routed through `MyEscaper::escape()`.

### Always-blocked functions

These are blocked **regardless of sandbox config or whitelist**:
`eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen`,
`file_get_contents`, `file_put_contents`, `fopen`, `include`, `require`,
`call_user_func`, `call_user_func_array`, `header`, `setcookie`, and others.

---

## Config File (`lex.config.json`)

### `LexConfig` API

```php
use Wik\Lexer\Config\LexConfig;

// Walk up from cwd until lex.config.json is found; throws if none found
$config = LexConfig::load();

// Load from an explicit path
$config = LexConfig::loadFrom('/path/to/lex.config.json');

// Try to load; returns null (no exception) if file not found
$config = LexConfig::tryLoad();

// Only find the file path (don't parse it yet)
$path = LexConfig::find('/path/to/project');   // string|null

// Inspect loaded values
$config->viewPaths;       // string[]
$config->production;      // bool
$config->sandbox;         // bool
$config->configFilePath;  // absolute path of the config file
$config->projectRoot;     // dirname($configFilePath)
```

### `Lexer::fromConfig()` — static factory

```php
// Zero-argument: walks up from cwd
$lexer = Lexer::fromConfig();

// From a specific directory (useful in tests)
$lexer = Lexer::fromConfig(__DIR__);

// Chain additional configuration on top of the file values
$lexer = Lexer::fromConfig()
    ->directive('money', fn($e) => "<?php echo number_format({$e}, 2); ?>")
    ->componentClassNamespace('App\\View\\Components');
```

---

## Production Mode

### Dev mode (default)

By default, production mode is **off**. On every `render()` call Lex checks
whether the source `.lex` file is newer than its cached compiled file. If it
is, the template is recompiled automatically. This means you never need to
clear or rebuild the cache during development — just save the file and reload.

> **Note:** `setProduction(false)` explicitly disables production mode if you
> need to reset it after enabling it programmatically.

### Enabling production mode

Enable after a warm-up compilation step in your deploy pipeline:

```php
// Via lex.config.json
// { "production": true }
$lexer = Lexer::fromConfig();

// Or programmatically
$lexer->setProduction();        // same as setProduction(true)
$lexer->setProduction(false);   // revert to dev mode
```

In production mode:
- A precompiled view index (`cache/index.php`) maps template paths to compiled paths.
- On each request the index is checked first — **no source-file I/O whatsoever**.
- Templates are **not recompiled** when the source changes; compile templates as part of every deployment to regenerate the index.

```php
// In your application bootstrap
$lexer->setProduction();
```

---

## Coding Standards for Contributors

- `declare(strict_types=1)` in every file
- Typed properties and return types everywhere
- No `static` singletons or global state
- All public methods documented with a one-line docblock
- PSR-4 namespace: `Wik\Lexer\`

- Tests in `tests/` using PHPUnit 10+
