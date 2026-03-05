# Syntax Reference

## Template file extension

All template files use the `.lex` extension by default.

---

## 1. Echo — Output Variables

### Escaped echo (HTML-safe)

```
{{ $variable }}
{{ $user->name }}
{{ strtoupper($title) }}
{{ implode(', ', $tags) }}
```

The expression is routed through the configured escaper (`htmlspecialchars` by default). Use this for all user-supplied data.

### Raw echo (unescaped)

```
{!! $htmlContent !!}
{!! $safeHtml !!}
```

Output is inserted **as-is**. Only use this for trusted HTML strings.

---

## 2. Comments

Template comments are **completely stripped** from the compiled output. They never appear in the browser's HTML source.

```html
<!-- This comment will NOT appear in the rendered HTML -->

<!--
  Multi-line comments work too.
  Great for disabling blocks during development.
-->

<p>This text IS rendered.</p>
```

> **Note:** This reuses HTML comment syntax so your editor gives you syntax highlighting. Unlike regular HTML comments, these are erased at compile time.

---

## 3. Conditionals

### Basic #if

```
#if ($user->isAdmin())
  <p>Admin panel</p>
#endif
```

### #if / #else

```
#if ($count > 0)
  <p>{{ $count }} items found.</p>
#else
  <p>No items found.</p>
#endif
```

### #if / #elseif / #else

```
#if ($score >= 90)
  <span>A</span>
#elseif ($score >= 75)
  <span>B</span>
#elseif ($score >= 60)
  <span>C</span>
#else
  <span>F</span>
#endif
```

All standard PHP expressions are valid inside the parentheses.

---

## 4. Loops

### #foreach

```
#foreach ($products as $product)
  <li>{{ $product->name }} — ${{ $product->price }}</li>
#endforeach
```

With key:

```
#foreach ($config as $key => $value)
  <dt>{{ $key }}</dt>
  <dd>{{ $value }}</dd>
#endforeach
```

### #while

```
#while ($queue->isNotEmpty())
  {{ $queue->dequeue() }}
#endwhile
```

### #break and #continue

Both work inside `#foreach` and `#while`:

```
#foreach ($items as $item)
  #if ($item->isDeleted())
    #continue
  #endif

  #if ($item->id === $targetId)
    <p>Found: {{ $item->name }}</p>
    #break
  #endif
#endforeach
```

Break / continue N levels:

```
#break(2)
#continue(2)
```

---

## 5. Switch

```
#switch ($order->status)

  #case ('pending')
    <span class="badge-yellow">Pending</span>
  #break

  #case ('processing')
    <span class="badge-blue">Processing</span>
  #break

  #case ('shipped')
  #case ('delivered')
    <span class="badge-green">{{ $order->status }}</span>
  #break

  #default
    <span class="badge-gray">Unknown</span>

#endswitch
```

- `#case (expr)` — the expression is any PHP value: `'string'`, `$var`, `Status::ACTIVE`, etc.
- `#default` — executed when no case matches.
- Omitting `#break` between cases enables **fall-through** (same as PHP).

---

## 6. Custom Directives

Register in PHP before rendering:

```php
$lexer->directive('uppercase', fn($expr) => "<?php echo strtoupper((string)({$expr})); ?>");
$lexer->directive('money',     fn($expr) => "<?php echo number_format((float)({$expr}), 2); ?>");
$lexer->directive('json',      fn($expr) => "<?php echo json_encode({$expr}, JSON_PRETTY_PRINT); ?>");
```

Use in templates:

```
#uppercase($product->name)
Price: #money($product->price)
<script>var data = #json($payload);</script>
```

See [Custom Directives](05-directives.md) for the full API.

---

## 7. Layout — #extends, #section, #yield

See [Layout System](04-layouts.md) for the full guide.

```
#extends('layouts.base')

#section('content')
  <h1>Welcome</h1>
#endsection
```

---

## 8. Components

See [Components](03-components.md) for the full guide.

Self-closing with a literal prop:
```html
<Alert type="success" />
<Card title="Hello" />
```

Self-closing with a **dynamic prop** (`:` prefix evaluates as PHP):
```html
<Card :title="$post->title" />
<Badge :count="count($items)" />
```

With default slot content:
```html
<Card title="User Profile">
  <p>{{ $user->bio }}</p>
</Card>
```

With named slots:
```html
<Panel>
  <slot name="header"><h1>Title</h1></slot>
  <p>Default slot content</p>
</Panel>
```

---

## 9. Push Stacks

See [Layout System — Push Stacks](04-layouts.md#4-push-stacks) for the full guide.

```
#push('scripts')
  <script src="/page.js"></script>
#endpush
```

Output in the layout:
```
#stack('scripts')
```

---

## Expression rules

- All expressions inside `{{ }}`, `{!! !!}`, and directive parentheses are raw PHP.
- Variable scope inside loops and conditions is the same as in PHP itself.
- In **sandbox mode** (`$lexer->enableSandbox()`), expressions are validated against a
  function whitelist and dangerous built-ins are blocked at compile time.
  See [Extending — Security / Sandbox](06-extending.md#security--sandbox-mode) for details.
