# Components

Components let you split your UI into reusable, self-contained pieces.
Each component is itself a `.lex` template that receives **props** and optional **slot content**.

---

## 1. Naming and File Resolution

Wik/Lexer resolves component tags to `.lex` files using these rules (in order):

1. **Explicit map** — registered with `$lexer->component('Name', '/path/to/file.lex')`
2. **Auto-discovery** — searches component directories for a matching file name

### Auto-discovery convention (zero config)

The `components/` subdirectory inside **every configured view path** is registered
automatically:

```
views/
├── home.lex
└── components/          ← discovered automatically
    ├── card.lex
    └── button-component.lex
```

The tag name is converted to kebab-case to find the file:

| Tag | File looked up |
|---|---|
| `<Card />` | `card.lex` or `Card.lex` |
| `<UserProfile />` | `user-profile.lex` or `UserProfile.lex` |
| `<button-component />` | `button-component.lex` |
| `<alert />` | `alert.lex` |

### Registering a single component explicitly

```php
$lexer->component('Alert', __DIR__ . '/views/components/alert.lex');
```

### PascalCase vs. lowercase

- **PascalCase** (`<Card>`, `<UserProfile>`) — always treated as a component.
- **lowercase** (`<card>`, `<alert>`) — treated as a component **unless** the tag name is a
  standard HTML5 element (`<div>`, `<p>`, `<span>`, etc.).
- Standard HTML elements always pass through unchanged as text.

---

## 2. Self-Closing Components

Use `<ComponentName prop="value" />` when you don't need slot content.

Template:
```html
<Badge label="New" color="green" />
```

Component file `components/badge.lex`:
```html
<span class="badge badge-{{ $color }}">{{ $label }}</span>
```

Rendered output:
```html
<span class="badge badge-green">New</span>
```

---

## 3. Components with Default Slot Content

Use an open/close tag to pass arbitrary HTML or nested components as the **default slot**.
The slot content is available as `$slot` inside the component template.

Template:
```html
<Card title="My Card">
  <p>This is the body of the card.</p>
  <a href="/read-more">Read more</a>
</Card>
```

Component file `components/card.lex`:
```html
<div class="card">
  <div class="card-header">
    <h2>{{ $title }}</h2>
  </div>
  <div class="card-body">
    {!! $slot !!}
  </div>
</div>
```

> `$slot` contains the fully-rendered HTML of the slot content, so use `{!! $slot !!}` (raw echo) to output it without double-escaping.

---

## 4. Named Slots

When a component needs more than one content region, use **named slots**.

Inside the parent template, wrap each region in `<slot name="…"> … </slot>`:

```html
<Panel>
  <slot name="header">
    <h1>Page Title</h1>
  </slot>

  <p>This goes into the default slot.</p>

  <slot name="footer">
    <p>Footer text</p>
  </slot>
</Panel>
```

Inside the component template `components/panel.lex`:
```html
<div class="panel">
  <header>{!! $slots['header'] ?? '' !!}</header>
  <main>{!! $slot !!}</main>
  <footer>{!! $slots['footer'] ?? '' !!}</footer>
</div>
```

- Named slot content is available as **`$slots['name']`** (associative array of strings).
- The default (unnamed) slot remains **`$slot`** (a plain string).
- Any slot not provided by the caller will be absent from `$slots` — always use `?? ''` as a fallback.

---

## 5. Props in Detail

### Literal string props

Quoted strings are passed as PHP string literals:

```html
<Alert type="success" message="Changes saved!" />
```

### Dynamic props — `:prop` prefix

Prefix a prop name with `:` to treat the value as a **PHP expression** evaluated at render time:

```html
<UserCard :name="$user->name" :score="$user->score * 100" />
```

Inside `user-card.lex`, `$name` and `$score` hold the live PHP values:
```html
<div>{{ $name }} — {{ $score }}pts</div>
```

Any PHP expression is valid after the `=`:

```html
<Badge :label="strtoupper($product->category)" />
<DataTable :rows="array_slice($items, 0, 10)" />
<Modal :open="$errors->any()" />
```

### Dynamic props — `{ }` expression syntax (alternative)

Wrap a PHP expression in curly braces without the `:` prefix:

```html
<Badge label={$user->name} count={count($notifications)} />
```

Both `:prop="expr"` and `prop={expr}` produce **identical compiled output** — choose whichever reads better.

| Syntax | Example | Compiled prop value |
|---|---|---|
| Literal | `title="Hello"` | `'Hello'` |
| `:` prefix | `:title="$post->title"` | `$post->title` |
| `{}` braces | `title={$post->title}` | `$post->title` |
| Boolean | `disabled` | `true` |

### Boolean props

A prop with no value is `true`:

```html
<Button disabled />
<Input type="text" required readonly />
```

---

## 6. Component Classes

For components that need PHP logic before rendering, create a **component class**.

The class is instantiated, its `mount()` method is called with matching prop names, and all **public properties** are injected into the template scope alongside the passed props.

### Register by name

```php
$lexer->registerComponentClass('Alert', App\View\Components\AlertComponent::class);
```

### Register by namespace (auto-discovery)

```php
// Looks for App\View\Components\{PascalCase}Component for every component tag
$lexer->componentClassNamespace('App\\View\\Components');
```

The tag name is **always normalized to PascalCase** before building the class name,
so `<card />`, `<Card />`, and `<card-name />` all resolve predictably:

| Tag | Class looked up |
|---|---|
| `<Alert />` | `App\View\Components\AlertComponent` |
| `<alert />` | `App\View\Components\AlertComponent` |
| `<card-name />` | `App\View\Components\CardNameComponent` |
| `<UserProfile />` | `App\View\Components\UserProfileComponent` |

> **The `Component` suffix is required.** The class `App\View\Components\AlertComponent` will
> be found, but `App\View\Components\Alert` will not. This prevents name collisions with
> non-component classes that might share the same namespace.

### Example

```php
namespace App\View\Components;

final class AlertComponent
{
    public string $icon    = '';
    public string $classes = '';

    public function mount(string $type = 'info', bool $dismissible = false): void
    {
        $this->icon    = match ($type) {
            'success' => '✓',
            'error'   => '✖',
            default   => 'ℹ',
        };
        $this->classes = "alert alert-{$type}" . ($dismissible ? ' alert-dismissible' : '');
    }
}
```

Usage in a template:
```html
<Alert type="success" dismissible />
```

`components/alert.lex`:
```html
<!-- Props: $type, $dismissible  |  Class properties: $icon, $classes -->
<div class="{{ $classes }}">
  <span>{{ $icon }}</span>
  {!! $slot !!}
  #if ($dismissible)
    <button class="close">&times;</button>
  #endif
</div>
```

**Rules:**
- `mount()` parameters are matched **by name** against the passed props.
- Parameters with default values are used when the matching prop is absent.
- Public class properties are merged **after** props — they can override a same-named prop.
- If no matching class is found, the component renders normally from props only.

---

## 7. Nested Components

Components can be used inside other components freely:

```html
<Panel title="Team Members">
  #foreach ($team as $member)
    <UserCard
      :name="$member->name"
      :email="$member->email"
      :role="$member->role"
    />
  #endforeach
</Panel>
```

A recursion guard prevents infinite self-referential components (limit: **50 levels**).

---

## 8. Components Inside Layouts

Components work inside layout sections without any special setup:

```
#extends('layouts.base')

#section('content')
  <Alert type="success">Profile updated!</Alert>
  <UserCard :name="$user->name" :email="$user->email" />
#endsection
```

---

## 9. Passing Complex Data

For objects and arrays, use `:prop` or `{ }`:

```html
<DataTable
  :columns="['Name', 'Email', 'Role']"
  :rows="$users->toArray()"
  :perPage="15"
/>
```

---

## 10. Registering Components Explicitly

If you prefer not to use auto-discovery, register each component:

```php
$lexer->component('Alert',       __DIR__ . '/views/components/alert.lex')
    ->component('Card',        __DIR__ . '/views/components/card.lex')
    ->component('UserProfile', __DIR__ . '/views/components/user-profile.lex');
```

The explicit registration always takes priority over auto-discovery.

---

Next: [Layout System →](04-layouts.md)
