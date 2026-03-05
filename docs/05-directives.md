# Directives

## Built-in Directives

| Directive | Description |
|---|---|
| `#if (expr)` | Open a conditional block |
| `#elseif (expr)` | Alternative conditional branch |
| `#else` | Fallback branch |
| `#endif` | Close the if block |
| `#foreach (expr)` | Open a foreach loop |
| `#endforeach` | Close the foreach loop |
| `#while (expr)` | Open a while loop |
| `#endwhile` | Close the while loop |
| `#switch (expr)` | Open a switch block |
| `#case (expr)` | Define a case branch |
| `#default` | Define the default branch |
| `#endswitch` | Close the switch block |
| `#break` | Break out of a loop or switch |
| `#break(N)` | Break N levels |
| `#continue` | Skip to next loop iteration |
| `#continue(N)` | Continue N levels up |
| `#extends('name')` | Declare the parent layout |
| `#section('name')` | Open a named section |
| `#endsection` | Close the current section |
| `#yield('name')` | Output a section (in layouts) |
| `#yield('name', 'default')` | Output a section with a default |
| `#parent` | Inject parent layout's version of current section |
| `#push('name')` | Start appending content to a named stack |
| `#endpush` | End the current push block |
| `#stack('name')` | Output all accumulated stack content (in layouts) |
| `#stack('name', 'default')` | Output stack content, or a fallback if empty |

---

## Custom Directives

### Registering a directive

```php
$lexer->directive('directiveName', function (string $expression): string {
    // $expression is the raw PHP string inside the parentheses
    // Return a PHP string to embed verbatim in the compiled template
    return "<?php /* your compiled PHP here */ ?>";
});
```

The handler is called **once at compile time**, not at render time.
The returned string must be valid PHP that can be embedded in a mixed HTML/PHP file.

### Simple example

```php
$lexer->directive('uppercase', fn($e) => "<?php echo strtoupper((string)({$e})); ?>");
```

Usage:
```
Hello, #uppercase($user->name)!
```

Output:
```html
Hello, JANE DOE!
```

### Formatting numbers

```php
$lexer->directive('money', function (string $expr): string {
    return "<?php echo '$' . number_format((float)({$expr}), 2); ?>";
});
```

Usage:
```
Price: #money($product->price)
```

### Date formatting

```php
$lexer->directive('date', function (string $expr): string {
    // $expr is expected to be  timestamp, 'format'  e.g.  $ts, 'Y-m-d'
    [$ts, $fmt] = explode(',', $expr, 2);
    $ts  = trim($ts);
    $fmt = trim(trim($fmt), "'\"");
    return "<?php echo date('{$fmt}', (int)({$ts})); ?>";
});
```

Usage:
```
Published: #date($post->publishedAt, 'M j, Y')
```

### Auth helpers

```php
$lexer->directive('auth',  fn($e) => "<?php if (auth()->check()): ?>");
$lexer->directive('guest', fn($e) => "<?php if (!auth()->check()): ?>");
$lexer->directive('endauth',  fn($e) => "<?php endif; ?>");
$lexer->directive('endguest', fn($e) => "<?php endif; ?>");
```

Usage:
```
#auth
  <a href="/logout">Log out, {{ auth()->user()->name }}</a>
#endauth

#guest
  <a href="/login">Log in</a>
#endguest
```

### Dumping for debugging

```php
$lexer->directive('dump', fn($e) => "<?php var_dump({$e}); ?>");
$lexer->directive('dd',   fn($e) => "<?php var_dump({$e}); exit; ?>");
```

### Emitting a PHP block

```php
$lexer->directive('php', fn($e) => "<?php {$e} ?>");
```

Usage:
```
#php($counter = 0)
#foreach ($items as $item)
  #php($counter++)
  <li>{{ $counter }}. {{ $item }}</li>
#endforeach
```

---

## Expression Handling

The `$expression` string your handler receives is the raw content inside `#name(...)`.
It may include commas, function calls, quoted strings, or PHP operators — parse it with
`explode`, `str_getcsv`, or any PHP string function as needed.

```
#myDirective($a, $b, 'literal')
```

```php
$lexer->directive('myDirective', function (string $expr): string {
    [$a, $b, $c] = array_map('trim', explode(',', $expr, 3));
    return "<?php myFunction({$a}, {$b}, {$c}); ?>";
});
```

---

## Important Notes

- Directives without parentheses (`#else`, `#break`) receive `null` as `$expression`.
- The handler **must** return a string — returning `null` or nothing will cause a TypeError.
- Custom directive output is embedded verbatim; if it contains syntax errors, PHP will fail at include time.
- Custom directives are resolved **at compile time**. Changing a handler requires clearing the cache.

---

Next: [Extending the Package →](06-extending.md)
