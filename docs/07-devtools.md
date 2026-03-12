# Lex DevTools — Chrome Extension

The **Lex DevTools** extension integrates the Lex template engine with Chrome DevTools,
providing a component inspector, error overlay, cache viewer, and network panel.

---

## How It Works

In **development mode** (default), `Lexer::render()` automatically wraps the output
with `LexDebugger`, which hooks into the runtime managers and injects a JSON payload
into every HTML response:

```html
<!-- injected before </body> -->
<script id="__lex_debug__" type="application/json">
  {"version":"1.0","renderTime":12.4,"components":[...],"cache":{...}}
</script>
```

The Chrome extension reads this payload and populates the DevTools panel.

In **production mode** (`setProduction()`), `LexDebugger` is bypassed entirely —
no payload is injected, no hooks run, zero overhead.

```
Dev mode:         Lexer::render() → LexDebugger → injects payload → extension reads it
Production mode:  Lexer::render() → ViewEngine directly → plain HTML
```

---

## Installation

### 1. Load the Chrome Extension

```
1. Open chrome://extensions
2. Enable "Developer mode" (top right)
3. Click "Load unpacked"
4. Select: lexer-extension/extension/
```

The Lex icon appears in the Chrome toolbar. A tab named **"Lex"** appears inside
Chrome DevTools when you open a page rendered by Lex in dev mode.

### 2. PHP Setup

No extra PHP is required. Just use `Lexer::render()` as normal — the debugger
activates automatically in dev mode:

```php
use Wik\Lexer\Lexer;

$lexer = Lexer::fromConfig();          // production: false → LexDebugger active
echo $lexer->render('home', $data);   // payload injected into HTML
```

To use `DebugMiddleware` (PSR-15 frameworks):

```php
use Wik\Lexer\Debug\DebugMiddleware;
use Wik\Lexer\Debug\LexDebugger;

// LexDebugger must be constructed to register hooks before render runs
$debugger = new LexDebugger($lexer);

// Middleware reads DebugPayload after the handler renders
$app->add(new DebugMiddleware());
```

---

## DevTools Panel

Open Chrome DevTools (`F12`) → **Lex** tab.

### Components Tab

Displays the full component tree rendered during the request.

| Column | Description |
|--------|-------------|
| Tree (left) | All components in render order, click to select |
| Detail (right) | Name, file path, props (name / value / type), slots, render time |
| `Highlight in Page` | Scrolls to and flashes the component's DOM element |

Props are shown with their binding type:

| Type | Example | Meaning |
|------|---------|---------|
| `literal` | `title="Hello"` | Static string |
| `expression` | `:user="$currentUser"` | PHP expression |
| `boolean` | `closable` | Bare attribute → `true` |

### Sections Tab

Lists every `#section` captured during the request.

| Column | Description |
|--------|-------------|
| Section | Section name |
| Defined In | Template that called `#section` |
| Preview | First 120 characters of the captured content |

### Cache Tab

Shows which templates were served from cache and which were recompiled.

| Status | Meaning |
|--------|---------|
| `HIT ✓` | Compiled PHP file was reused (fast path) |
| `MISS ✗` | Template was recompiled this request |

### Network Tab

Lists all Lex-rendered requests in the current browser session.

Requires the `X-Lex-Debug`, `X-Lex-Render-Time`, and `X-Lex-Cache-Hits` response
headers set by `DebugMiddleware`. Without the middleware only the latest page's data
is visible.

### Timeline Tab

Gantt chart of component render times relative to total page render time.

---

## Error Overlay

When Lex throws a `TemplateSyntaxException` or similar, the extension intercepts
the error HTML and replaces it with a readable overlay:

```
┌──────────────────────────────────────────────────────────┐
│  🔴  TemplateSyntaxException                     [×]     │
│  Unexpected token "}" in expression                      │
│  📄  views/home.lex — line 12, col 5                    │
│  ──────────────────────────────────────────────────────  │
│   10 │  <div class="wrapper">                           │
│   11 │    {{ $title                                     │
│ ▶ 12 │    }}                                            │
│  ──────────────────────────────────────────────────────  │
│                            [Open in VS Code]             │
└──────────────────────────────────────────────────────────┘
```

Works **without** `LexDebugger` — the overlay parses the raw PHP error output.
With `LexDebugger` active it uses richer data from the `errors[]` payload field.

Dismiss with `Esc` or the `✕` button.

---

## Hover Inspector

Toggle inspect mode with `Alt+Shift+X` or the popup button.

- **Hover** over any element → tooltip shows component name, file, render time
- **Click** → selects the component in the DevTools Lex panel

Requires `data-lex-*` attributes on component root elements, which are injected
by `LexDebugger`.

---

## Popup

Click the Lex toolbar icon to:

| Control | Effect |
|---------|--------|
| Inspect Mode toggle | Toggle `Alt+Shift+X` shortcut |
| Error Overlay toggle | Enable/disable the error overlay |
| Status indicator | Shows whether the current page has a Lex payload |

---

## Disabling in Production

Set `"production": true` in `lex.config.json`:

```json
{ "production": true }
```

Or call `setProduction()` in your bootstrap:

```php
$lexer = Lexer::fromConfig()->setProduction();
```

`LexDebugger` will not be instantiated, no hooks will run, and no
`<script id="__lex_debug__">` tag will be injected.

---

## Debug Hook API

If you need to hook into the render pipeline for your own tooling (logging,
APM, custom panels), use the same hook API that `LexDebugger` uses:

```php
$lexer->getComponentManager()->addHook(
    'onComponentStart',
    function (string $name, string $file, array $props): void {
        // $name = 'Card', $file = '/abs/path/card.lex', $props = [...]
    }
);

$lexer->getComponentManager()->addHook(
    'onComponentEnd',
    function (string $name, string $file, float $renderMs): void {
        // $renderMs = 2.1
    }
);

$lexer->getSectionManager()->addHook(
    'onSectionEnd',
    function (string $name, string $content): void {
        // called after every #section / #endsection pair
    }
);

$lexer->getEngine()->getCompiler()->getCache()->addHook(
    'onCacheHit',
    function (string $key, string $compiledPath): void {}
);

$lexer->getEngine()->getCompiler()->getCache()->addHook(
    'onCacheMiss',
    function (string $key): void {}
);
```

All hook arrays are empty by default — there is no overhead when no hooks are
registered.

---

Previous: [Extending →](06-extending.md)
