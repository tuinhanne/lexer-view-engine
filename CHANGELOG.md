# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-03-05

### Added

#### Core Engine
- AST-based compilation pipeline: Lexer → Parser → Validator → Optimizer → Code generation
- FileCache with atomic writes and production-mode precompiled index
- AST serialization with igbinary (fallback: PHP `serialize` with `allowed_classes`)
- Sandbox mode with function whitelist and expression validation

#### Directives
- **Control flow**: `#if`, `#elseif`, `#else`, `#endif`
- **Loops**: `#foreach`, `#endforeach` with `$loop` variable; `#for`, `#endfor`; `#while`, `#endwhile`
- **Loop control**: `#break`, `#break(N)`, `#continue`, `#continue(N)`
- **Inverse conditional**: `#unless`, `#endunless`
- **Existence checks**: `#isset`, `#endisset`, `#empty`, `#endempty`
- **Switch**: `#switch`, `#case`, `#default`, `#endswitch`
- **Layout**: `#extends`, `#section`, `#endsection`, `#yield`, `#parent`
- **Push stacks**: `#push`, `#endpush`, `#stack`
- **Includes**: `#include`, `#includeIf`, `#includeWhen`, `#includeFirst`
- **Raw PHP**: `#php`, `#endphp`
- **Debug**: `#dump`, `#dd`
- **Custom directives**: user-registered via `$lex->directive(name, handler)`

#### `$loop` Variable (in `#foreach`)
Available properties: `index`, `iteration`, `remaining`, `count`, `first`, `last`,
`even`, `odd`, `depth`, `parent` (nested loop support).

#### Template Syntax
- Escaped echo: `{{ $expr }}` → `htmlspecialchars` by default (customizable via EscaperInterface)
- Raw echo: `{!! $expr !!}`
- Template comments: `<!-- ... -->` stripped at lex time

#### Components
- PascalCase tags: `<Card title="Hello" />`
- Named slots: `<slot name="header">...</slot>`
- Dynamic props: `:prop="$phpExpr"`
- Boolean props: `<Alert closable />`
- Component classes with `mount()` method and reflection-based prop injection

#### Security
- `SandboxConfig` with `permissive()` and `secure()` presets
- `ExpressionValidator` with 50+ always-blocked functions
- `HtmlEscaper` as default output escaper

#### CLI (`bin/lex`)
- `lex compile <path>` — precompile all templates
- `lex cache:clear <dir>` — clear compiled cache
- `lex benchmark <template>` — measure render performance
- `lex validate <path>` — validate templates without rendering

#### Loaders
- `FileLoader` — dot-notation template names (`layouts.main`)
- `MemoryLoader` — in-memory templates for testing
- `NamespaceLoader` — namespaced templates (`admin::dashboard`)

#### Exceptions
- `TemplateSyntaxException` — compile-time errors with file/line/column/snippet
- `TemplateRuntimeException` — runtime errors (infinite loop, recursion limit)
