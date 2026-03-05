# Layout System

Layouts let you define a base HTML skeleton once and have individual pages fill in the named sections.

---

## 1. Concepts

| Directive | Where | Purpose |
|---|---|---|
| `#yield('name')` | Layout | Marks where a section's content will be inserted |
| `#yield('name', 'default')` | Layout | Same, with a fallback string if section is absent |
| `#extends('layout')` | Child page | Declares which layout to inherit |
| `#section('name')` … `#endsection` | Child page | Defines content for a named section |
| `#parent` | Inside `#section` | Injects the parent layout's version of the current section |
| `#push('name')` … `#endpush` | Child page / component | Appends content to a named stack |
| `#stack('name')` | Layout | Outputs all accumulated stack content |

---

## 2. Create a Layout

`views/layouts/base.lex`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>#yield('title', 'My App')</title>
  <link rel="stylesheet" href="/app.css">
  #yield('head')
  #stack('styles')
</head>
<body>

  <nav>
    <a href="/">Home</a>
    <a href="/about">About</a>
  </nav>

  <main>
    #yield('content')
  </main>

  <footer>
    <p>&copy; {{ date('Y') }} My App</p>
  </footer>

  #stack('scripts')
</body>
</html>
```

- `#yield('name')` — outputs a section. Nothing is output if the child doesn't define it.
- `#yield('name', 'default text')` — outputs a fallback if the section is not defined.
- `#stack('name')` — outputs all content pushed to a stack (in push order).

---

## 3. Extend the Layout in a Page

`views/home.lex`:

```
#extends('layouts.base')

#section('title')
Home — My App
#endsection

#section('content')
  <h1>Welcome, {{ $user->name }}!</h1>
  <p>You have {{ $notifications }} unread messages.</p>
#endsection

#push('scripts')
  <script src="/home.js"></script>
#endpush
```

- `#extends` must reference the layout using **dot notation** (`layouts.base` = `layouts/base.lex`).
- Sections can be defined in any order.
- Content outside `#section` … `#endsection` in an extending template is discarded.

---

## 4. Push Stacks

`#push` / `#endpush` lets multiple templates **append** content to the same slot in a layout,
without overwriting each other. This is ideal for page-specific scripts and stylesheets.

Layout `views/layouts/base.lex` defines the output point:
```html
<head>
  #stack('styles')
</head>
<body>
  ...
  #stack('scripts')
</body>
```

A page pushes to the stacks:
```
#extends('layouts.base')

#push('styles')
  <link rel="stylesheet" href="/dashboard.css">
#endpush

#push('scripts')
  <script src="/chart.js"></script>
#endpush

#push('scripts')
  <script src="/dashboard.js"></script>
#endpush

#section('content')
  ...
#endsection
```

**Behaviour:**
- Multiple `#push` blocks for the same stack are **concatenated in order**.
- `#stack('name', 'fallback')` — outputs a fallback string if nothing was pushed.
- Components can also push to stacks — the content is available in the parent layout.

---

## 5. The `#parent` Directive

`#parent` inside a `#section` block injects the **parent layout's version** of that section.
Use it to extend rather than fully replace a section.

Layout `views/layouts/base.lex`:
```html
<nav>
  #yield('nav')
</nav>
```

A mid-level layout `views/layouts/app.lex`:
```
#extends('layouts.base')

#section('nav')
  <a href="/">Home</a> | <a href="/about">About</a>
#endsection
```

A page that adds to the nav without replacing it:
```
#extends('layouts.app')

#section('nav')
  #parent
  | <a href="/dashboard">Dashboard</a>
#endsection
```

Rendered `<nav>` output:
```html
<nav>
  <a href="/">Home</a> | <a href="/about">About</a>
  | <a href="/dashboard">Dashboard</a>
</nav>
```

---

## 6. Multiple Sections

You can have as many yield/section pairs as needed:

Layout `views/layouts/dashboard.lex`:
```html
<!DOCTYPE html>
<html>
<head>
  <title>#yield('title', 'Dashboard')</title>
  #stack('styles')
</head>
<body>
  <aside>#yield('sidebar')</aside>
  <main>#yield('content')</main>
  <div id="modals">#yield('modals')</div>
  #stack('scripts')
</body>
</html>
```

Page `views/settings.lex`:
```
#extends('layouts.dashboard')

#section('title')Settings#endsection

#section('sidebar')
  <ul>
    <li><a href="/settings/profile">Profile</a></li>
    <li><a href="/settings/security">Security</a></li>
  </ul>
#endsection

#section('content')
  <h1>Settings</h1>
  <!-- settings form -->
#endsection

#push('scripts')
  <script src="/settings.js"></script>
#endpush
```

---

## 7. Layout with Components

Layouts and components work together naturally:

```html
<!-- layouts/app.lex -->
<!DOCTYPE html>
<html>
<body>
  <Navbar brand="My App" />
  <main>
    #yield('content')
  </main>
  <Footer />
  #stack('scripts')
</body>
</html>
```

---

## 8. Multi-Level Inheritance

A layout can itself extend another layout:

`views/layouts/base.lex`:
```html
<!DOCTYPE html>
<html>
<body>
  #yield('body')
  #stack('scripts')
</body>
</html>
```

`views/layouts/app.lex`:
```
#extends('layouts.base')

#section('body')
  <nav>...</nav>
  <main>#yield('content')</main>
#endsection
```

`views/page.lex`:
```
#extends('layouts.app')

#section('content')
  <h1>Hello</h1>
#endsection
```

The rendering chain is:
```
page.lex → layouts/app.lex → layouts/base.lex
```

---

## 9. Default Section Content

Use the second argument of `#yield` to set a fallback when no child defines the section:

```
#yield('sidebar', '<p>No sidebar configured.</p>')
```

The fallback is output as a PHP string literal — it is **not** processed as a Lex template.

---

## 10. Rendering Order

1. The child template is executed first (sections are captured, layout name stored).
2. The direct output of the child (anything outside `#section` or `#push`) is discarded.
3. The layout is executed using the **same environment** — `#yield` draws from captured sections, `#stack` outputs accumulated pushes.
4. If the layout itself extends another layout, the process repeats upward.

Templates are always executed **top-down**; layouts are assembled **inside-out**.

---

## 11. Infinite Loop Protection

If two layouts extend each other (A → B → A), the engine detects the loop and throws
`TemplateRuntimeException` immediately — no infinite PHP recursion.

This is enforced automatically; no configuration is needed.

---

Next: [Directives →](05-directives.md)
