# Installation

## Requirements

- PHP **8.1** or higher
- Composer

## Install via Composer

```bash
composer require wik/lexer
```

## Quick Setup — with `lex.config.json` (recommended)

Run `lex init` once in the project root. It creates `lex.config.json` interactively and optionally writes `.vscode/settings.json` for the LSP extension:

```bash
vendor/bin/lex init
```

Example `lex.config.json`:

```json
{
  "viewPaths":  ["views", "resources/views"],
  "extension":  "lex",
  "production": false,
  "sandbox":    false
}
```

Then use the config-file factory in your PHP code:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Wik\Lexer\Lexer;

// Reads lex.config.json by walking up from cwd — no hardcoded paths
$lexer = Lexer::fromConfig();

echo $lexer->render('home', ['title' => 'Welcome']);
```

Create `views/home.lex`:

```html
<!DOCTYPE html>
<html>
<head><title>{{ $title }}</title></head>
<body>
  <h1>{{ $title }}</h1>
</body>
</html>
```

The `.lexer/` cache directory is automatically created at the project root (alongside `lex.config.json`) and requires no manual setup or permissions configuration.

---

## Quick Setup — manual fluent API

```php
use Wik\Lexer\Lexer;

$lexer = (new Lexer())
    ->paths([__DIR__ . '/views']);

echo $lexer->render('home', ['title' => 'Welcome']);
```

When no `lex.config.json` is found the cache is placed at `{cwd}/.lexer/`.

---

## `lex.config.json` Reference

The config file is searched by **walking up** from the current working directory (same strategy as ESLint / Prettier). Place it at the project root.

| Field | Type | Default | Description |
|---|---|---|---|
| `viewPaths` | `string[]` | `["views","resources/views"]` | Directories scanned for `.lex` templates. Relative paths are resolved from the config file's directory. |
| `extension` | `string` | `"lex"` | Template file extension (without the dot). |
| `production` | `bool` | `false` | Enable production mode (precompiled index, skip source I/O). |
| `sandbox` | `bool` | `false` | Enable secure sandbox mode. |

The same file is read by the [Lex LSP extension](../lex-language-server/) for component IntelliSense.

---

## Fluent API Reference

| Method | Description |
|---|---|
| `Lexer::fromConfig(string $dir = '')` | **Static factory** — load from `lex.config.json` |
| `paths(array $dirs)` | Set the directories searched for `.lex` files |
| `addPath(string $dir)` | Append one directory to the search path |
| `extension(string $ext)` | Change the template file extension (default: `lex`) |
| `setProduction(bool $v = true)` | Enable (`true`, default) or disable (`false`) production mode. In production, templates are never recompiled from source — run `lex compile` during deployment instead. |
| `enableSandbox(?SandboxConfig $cfg)` | Enable sandbox mode |
| `directive(string $name, callable $fn)` | Register a custom directive |
| `component(string $name, string $file)` | Map a component tag to a view file |
| `componentClassNamespace(string $ns)` | Namespace prefix for component classes |
| `setEscaper(EscaperInterface $e)` | Override the HTML escaper |
| `render(string $name, array $data)` | Render a template by name and return HTML |
| `renderFile(string $path, array $data)` | Render a template by absolute path |

---

## Directory Layout (Recommended)

```
my-project/
├── lex.config.json         ← project config (created by `lex init`)
├── composer.json
├── views/
│   ├── layouts/
│   │   └── app.lex
│   ├── components/
│   │   ├── Card.lex
│   │   └── Alert.lex
│   └── home.lex
└── .lexer/                 ← cache root (auto-created, add to .gitignore)
    ├── compiled/           ← compiled PHP files ({md5}.php)
    └── ast/                ← serialised AST snapshots ({md5}.ast)
```

Add `.lexer/` to your `.gitignore`:

```
.lexer/
```

---

## Framework Integration

```php
// Option A: use lex.config.json (recommended)
$this->app->singleton(Lexer::class, fn() => Lexer::fromConfig());

// Option B: manual configuration
$this->app->singleton(Lexer::class, function () {
    return (new Lexer())
        ->paths([resource_path('views')]);   // views/components/ is auto-registered
        // cache is placed at {cwd}/.lexer/ automatically
});
```

Next: [Syntax Reference →](02-syntax.md)
