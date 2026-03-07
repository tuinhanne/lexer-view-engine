# Wik/Lex

A modern, production-grade **AST-based view engine** for PHP 8.1+.
Works with any PHP project — no framework required.

```
composer require wik/lexer
```

---

## Features

- **AST compilation pipeline** — Lexer → Parser → Validator → Optimizer → PHP file
- **File cache** with atomic writes; production-mode precompiled index
- **Layout inheritance** — `#extends`, `#section`, `#yield`, `#parent`
- **Components** — PascalCase tags, named slots, dynamic props, class mounting
- **`$loop` variable** — full metadata inside every `#foreach`
- **Include system** — `#include`, `#includeIf`, `#includeWhen`, `#includeFirst`
- **Sandbox mode** — expression whitelist, raw-echo control, 50+ always-blocked functions
- **Custom directives** — register any PHP callable as a template directive
- **CLI tooling** — init, compile, cache:clear, benchmark, validate
- **Config file** — `lex.config.json` at the project root; `Lexer::fromConfig()` factory

---

## Requirements

| | |
|---|---|
| PHP | `^8.1` |
| Extensions | `mbstring` (recommended), `igbinary` (optional, faster AST cache) |
| Dev dependencies | `phpunit/phpunit`, `symfony/console` |

---

## Installation

```bash
composer require wik/lexer
```

---

## Quick Start

### Option A — with `lex.config.json` (recommended)

```bash
vendor/bin/lex init
```

Creates `lex.config.json` in the project root:

```json
{
  "viewPaths":  ["views", "resources/views"],
  "cache":      "cache/views",
  "production": false,
  "sandbox":    false
}
```

Then use the config-file factory anywhere in your code:

```php
use Wik\Lexer\Lexer;

$lexer = Lexer::fromConfig();   // reads lex.config.json, walks up from cwd
echo $lexer->render('home', ['title' => 'Hello World', 'user' => $user]);
```

### Option B — manual fluent API

```php
use Wik\Lexer\Lexer;

$lexer = (new Lexer())
    ->paths([__DIR__ . '/views'])
    ->cache(__DIR__ . '/storage/cache/views');

echo $lexer->render('home', ['title' => 'Hello World', 'user' => $user]);
```

**`views/home.lex`**

```
<h1>{{ $title }}</h1>

#isset($user)
    <p>Welcome back, {{ $user->name }}!</p>
#endisset
```

---

## Template Syntax

### Echo

| Syntax | Output |
|--------|--------|
| `{{ $expr }}` | HTML-escaped (safe) |
| `{!! $expr !!}` | Raw / unescaped |

```
<h1>{{ $post->title }}</h1>
<div>{!! $post->htmlBody !!}</div>
```

### Comments

HTML comments are **stripped at lex time** — they never appear in compiled output.

```
<!-- This comment will not appear in the rendered HTML -->
```

### Escaping `#`

Prefix `#` with a backslash to output it literally without triggering a directive:

```
<code>\#truncate($text, 60)</code>  <!-- renders: #truncate($text, 60) -->
```

Only `\#` followed by a letter is treated as an escape; `\#123` or standalone `\` are output as-is.

---

## Directives

### Conditionals

```
#if($user->isAdmin())
    <p>Admin panel</p>
#elseif($user->isModerator())
    <p>Moderator panel</p>
#else
    <p>Guest view</p>
#endif
```

```
#unless($user->isVerified())
    <p>Please verify your email.</p>
#endunless
```

### Existence Checks

```
#isset($sidebar)
    <aside>{{ $sidebar }}</aside>
#endisset

#empty($notifications)
    <p>No new notifications.</p>
#endempty
```

### Loops

**`#foreach`** — with full `$loop` variable:

```
#foreach($posts as $post)
    <article class="{{ $loop->even ? 'even' : 'odd' }}">
        <h2>{{ $loop->iteration }}. {{ $post->title }}</h2>

        #if($loop->first)
            <span class="badge">Latest</span>
        #endif
    </article>
#endforeach
```

**`$loop` properties:**

| Property | Type | Description |
|----------|------|-------------|
| `$loop->index` | `int` | 0-based position |
| `$loop->iteration` | `int` | 1-based position |
| `$loop->count` | `int` | Total items |
| `$loop->remaining` | `int` | Items left after current |
| `$loop->first` | `bool` | Is this the first item? |
| `$loop->last` | `bool` | Is this the last item? |
| `$loop->even` | `bool` | Even index (0, 2, 4…) |
| `$loop->odd` | `bool` | Odd index (1, 3, 5…) |
| `$loop->depth` | `int` | Nesting depth (1 = outermost) |
| `$loop->parent` | `object\|null` | Parent `$loop` in nested loops |

**`#for`**

```
#for($i = 1; $i <= 5; $i++)
    <p>Item {{ $i }}</p>
#endfor
```

**`#while`**

```
#while($queue->isNotEmpty())
    {{ $queue->pop() }}
#endwhile
```

**Loop control:**

```
#foreach($items as $item)
    #if($item->isHidden())
        #continue
    #endif

    #if($item->isLast())
        #break
    #endif

    {{ $item->name }}
#endforeach
```

Multi-level break/continue: `#break(2)`, `#continue(2)`

### Switch

```
#switch($status)
    #case('active')
        <span class="green">Active</span>
    #break
    #case('banned')
        <span class="red">Banned</span>
    #break
    #default
        <span>Unknown</span>
#endswitch
```

---

## Includes

```
<!-- Basic include -->
#include('partials.header')

<!-- With additional data -->
#include('partials.nav', ['active' => 'home'])

<!-- Only include if the template file exists -->
#includeIf('partials.sidebar')

<!-- Conditionally include -->
#includeWhen($user->isAdmin(), 'partials.admin-bar')

<!-- First match wins -->
#includeFirst(['theme.header', 'partials.header'])
```

Included templates are rendered in an **isolated scope** — their sections do not
leak into the parent layout's `#yield` slots.

---

## Layout Inheritance

**`views/layouts/app.lex`**

```html
<!DOCTYPE html>
<html>
<head>
    <title>#yield('title', 'My App')</title>
    #stack('styles')
</head>
<body>
    #yield('content')

    #stack('scripts')
</body>
</html>
```

**`views/pages/home.lex`**

```
#extends('layouts.app')

#section('title')
    Home Page
#endsection

#section('content')
    <h1>Welcome</h1>
    <p>Hello, {{ $user->name }}!</p>
#endsection

#push('scripts')
    <script src="/app.js"></script>
#endpush
```

### `#parent` — Extend a Section

```
#section('sidebar')
    #parent
    <p>Extra sidebar content appended after the layout's sidebar.</p>
#endsection
```

### `#stack` with a Default

```
#stack('scripts', '<script src="/default.js"></script>')
```

---

## Components

### Self-closing

```
<Alert type="warning" message="Low disk space." />
<Badge :count="$notifications->unread()" />
```

### With Children / Slots

```
<Card :title="$post->title">
    <p>{{ $post->excerpt }}</p>

    <slot name="footer">
        <a href="{{ $post->url }}">Read more</a>
    </slot>
</Card>
```

**`views/components/card.lex`**

```html
<div class="card">
    <div class="card-header">{{ $title }}</div>
    <div class="card-body">{{ $slot }}</div>
    <div class="card-footer">#yield('footer')</div>
</div>
```

### Component Discovery

Components are resolved automatically from a `components/` subdirectory inside
each configured view path (e.g. `views/components/`). No extra configuration is needed.

Any tag whose name is **not** a standard HTML5/SVG/MathML element is treated as a
component — both PascalCase and kebab-case names work:

```
<UserProfile />
<user-profile />
<user-profile></user-profile>
```

Given any of the above, Lex looks for the component file (in order):
1. `user-profile.lex`
2. `UserProfile.lex`
3. `userprofile.lex`

To register a component explicitly by file path:

```php
$lexer->component('Alert', __DIR__ . '/views/components/alert.lex');
```

### Component Classes

```php
namespace App\View\Components;

class Alert
{
    public string $class;

    public function mount(string $type, string $message): void
    {
        $this->class   = 'alert alert-' . $type;
        $this->message = $message;
    }
}
```

```php
$lexer->componentClassNamespace('App\\View\\Components');
```

Props are automatically injected into `mount()` via reflection. All public
properties of the class instance are available in the component template.

Auto-discovery looks for `{PascalCase}Component` — e.g. `<Alert>` resolves
to `App\View\Components\AlertComponent`.

To register a class explicitly:

```php
$lexer->registerComponentClass('Alert', App\View\Components\AlertComponent::class);
```

### Prop Types

| Syntax | PHP type | Example |
|--------|----------|---------|
| `prop="value"` | `string` literal | `title="Hello"` |
| `:prop="$expr"` | PHP expression | `:user="$currentUser"` |
| `prop` (bare) | `true` (boolean) | `closable` |

---

## Raw PHP Blocks

For standalone PHP projects that need inline PHP logic:

```
#php
    $total   = array_sum(array_column($items, 'price'));
    $tax     = $total * 0.1;
    $display = number_format($total + $tax, 2);
#endphp

<p>Total: ${{ $display }}</p>
```

> **Note:** `#php` blocks are disabled in sandbox mode.

---

## Debug Helpers

```
#dump($variable)       <!-- var_dump() -->
#dd($variable)         <!-- var_dump() + exit(1) -->
```

---

## Custom Directives

```php
$lexer->directive('datetime', function (string $expression): string {
    return "<?php echo date('d/m/Y H:i', strtotime({$expression})); ?>";
});
```

**Template:**

```
#datetime($post->created_at)
```

Custom directives are resolved at **parse time** — the callable runs once
during compilation, not on every render.

---

## Configuration

### Config file (`lex.config.json`)

Place `lex.config.json` at the project root. All paths may be relative (resolved from the file's own directory) or absolute.

```json
{
  "viewPaths":  ["views", "resources/views"],
  "cache":      "cache/views",
  "production": false,
  "sandbox":    false
}
```

| Field | Type | Default | Description |
|---|---|---|---|
| `viewPaths` | `string[]` | `["views","resources/views"]` | Directories scanned for `.lex` templates |
| `cache` | `string` | `"cache/views"` | Compiled-file cache directory |
| `production` | `bool` | `false` | Enable production mode on startup |
| `sandbox` | `bool` | `false` | Enable secure sandbox mode |

The CLI commands (`compile`, `validate`, `benchmark`) read this file automatically when no explicit options are given.
The [Lex LSP extension](../lex-language-server/) also reads the same file to power IntelliSense.

`LexConfig` walks up from the current working directory to find the file, so you can call `Lexer::fromConfig()` from anywhere inside the project without passing a path.

### Fluent API

```php
use Wik\Lexer\Lexer;
use Wik\Lexer\Security\SandboxConfig;

$lexer = (new Lexer())
    // View directories (dot-notation resolution)
    ->paths([__DIR__ . '/views'])

    // Add a single directory without replacing existing ones
    ->addPath(__DIR__ . '/views/vendor')

    // Cache directory
    ->cache(__DIR__ . '/storage/cache/views')

    // Enable production mode (precompiled index, skip source checks)
    ->setProduction()

    // Custom HTML escaper
    ->setEscaper(new MyCustomEscaper())

    // Sandbox mode
    ->enableSandbox()
    ->setSandboxConfig(
        SandboxConfig::secure()
            ->withAllowedFunctions(['strtolower', 'strtoupper', 'number_format'])
    )

    // Component class namespace
    ->componentClassNamespace('App\\View\\Components')

    // Register a component explicitly by file path
    ->component('Alert', __DIR__ . '/views/components/alert.lex')

    // Custom directives
    ->directive('money', fn($e) => "<?php echo number_format({$e}, 2); ?>")
    ->directive('uppercase', fn($e) => "<?php echo strtoupper({$e}); ?>");
```

---

## Sandbox Mode

Sandbox mode restricts what template authors can do — useful for user-submitted templates.

```php
use Wik\Lexer\Security\SandboxConfig;

// Permissive (only removes always-blocked functions like exec, eval, system…)
$config = SandboxConfig::permissive();

// Secure (raw echo forbidden, custom directives forbidden, strict function whitelist)
$config = SandboxConfig::secure()
    ->withAllowedFunctions(['date', 'number_format', 'strtolower'])
    ->withRawEcho(false);

$lexer->enableSandbox()->setSandboxConfig($config);
```

**Always-blocked** regardless of config: `eval`, `exec`, `system`, `shell_exec`,
`passthru`, `popen`, `proc_open`, `file_get_contents`, `file_put_contents`,
`include`, `require`, `curl_exec`, backtick operator, and 40+ others.

---

## Loaders

### File Loader (default)

```php
$lexer->paths([
    __DIR__ . '/views',
    __DIR__ . '/views/vendor',
]);
```

Template `'layouts.app'` resolves to `views/layouts/app.lex`.

### Namespace Loader

```php
use Wik\Lexer\Loader\NamespaceLoader;

$loader = new NamespaceLoader();
$loader->addNamespace('admin', __DIR__ . '/views/admin');
$loader->addNamespace('mail',  __DIR__ . '/views/mail');
```

Template `'admin::dashboard'` resolves to `views/admin/dashboard.lex`.

### Memory Loader (testing)

```php
use Wik\Lexer\Loader\MemoryLoader;

$loader = new MemoryLoader();
$loader->set('greeting', 'Hello, {{ $name }}!');
```

---

## CLI

### Setup

```bash
# Create lex.config.json
vendor/bin/lex init

# Non-interactive (use all defaults)
vendor/bin/lex init --defaults

# Specify a project directory other than cwd
vendor/bin/lex init --dir=/path/to/project

# Overwrite an existing lex.config.json
vendor/bin/lex init --force
```

### Commands (with `lex.config.json` all options become optional)

```bash
# Precompile all viewPaths from config (deploy step)
vendor/bin/lex compile

# …or target a specific path / file
vendor/bin/lex compile views/home.lex --cache=storage/cache --production

# Clear the compiled cache
vendor/bin/lex cache:clear ./storage/cache/views

# Validate all templates from config
vendor/bin/lex validate

# …or with sandbox rules
vendor/bin/lex validate views/ --sandbox

# Benchmark render performance
vendor/bin/lex benchmark home --iterations=1000
```

When `lex.config.json` is present in the project root (or any parent directory), `compile`, `validate`, and `benchmark` automatically read `viewPaths` and `cache` from it. Explicit CLI options always take precedence.

---

## Render a File Directly

```php
echo $lexer->renderFile('/absolute/path/to/template.lex', ['key' => 'value']);
```

---

## Escaping

The default escaper uses `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

Implement a custom escaper:

```php
use Wik\Lexer\Contracts\EscaperInterface;

class MarkdownEscaper implements EscaperInterface
{
    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$lexer->setEscaper(new MarkdownEscaper());
```

---

## Production Mode

**Dev mode (default — production off):** on every `render()` call Lex checks
whether the source `.lex` file has changed and recompiles it automatically.
No manual cache management is needed during development.

**Production mode:** all source-file I/O is skipped. Templates are served
directly from a precompiled index — zero recompilation per request. You must
run `lex compile` during every deployment to keep compiled files up to date.

```bash
# During deployment — compile all templates and write the index
vendor/bin/lex compile --production
```

```php
// In your application bootstrap
$lexer->setProduction();          // enable
// $lexer->setProduction(false);  // revert to dev mode if needed
```

---

## Exceptions

| Exception | When |
|-----------|------|
| `TemplateSyntaxException` | Parse/compile error with file, line, column, and source snippet |
| `TemplateRuntimeException` | Infinite layout loop, component recursion limit exceeded |
| `ViewException` | Template not found, no view paths configured |
| `LexerException` | Unterminated `{{ }}` or `{!! !!}` |
| `ParseException` | Unmatched blocks, unknown directives |
| `CompilerException` | Cache directory not writable |

---

## Architecture

```
Template source
    │
    ▼
Lexer           character-by-character tokenizer (no regex structural parsing)
    │  Token[]
    ▼
Parser          explicit stack → nested AST
    │  Node[]
    ▼
AstValidator    sandbox enforcement, structural checks (optional)
    │  Node[]
    ▼
OptimizePass    merge adjacent TextNodes, remove empty nodes (optional)
    │  Node[]
    ▼
Code generation Node::compile() → PHP source string
    │  string
    ▼
FileCache       atomic write → {cacheDir}/{md5(source)}.php
    │  path
    ▼
include()       executed in isolated scope with $__env injected
```

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

---

## License

MIT — see [LICENSE](LICENSE).
