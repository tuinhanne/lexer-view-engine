<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler;

use Wik\Lexer\Exceptions\LexerException;

/**
 * Character-by-character lexer that converts a .lex template source into a flat
 * array of Token objects.  No regular expressions are used for structural scanning.
 *
 * Component tag detection rules:
 *   - Any tag whose name is NOT in the HTML_TAGS blocklist is treated as a component.
 *   - This means <Card>, <UserProfile>, <card>, <my-widget> are all components.
 *   - Standard HTML elements (<div>, <p>, <span>, …) are treated as raw text.
 *
 * Dynamic attribute binding:
 *   - Props with a `:` prefix are treated as PHP expression bindings:
 *     <Card :title="$post->title" /> produces an 'expression' prop for 'title'.
 *
 * Template comments:  <!-- comment content -->
 *   Comments are completely stripped from the token stream and from compiled output.
 */
final class Lexer
{
    // -----------------------------------------------------------------------
    // HTML5 / SVG / MathML element blocklist
    // Tag names in this set are NEVER treated as component tags.
    // -----------------------------------------------------------------------

    /** @var array<string, true> */
    private const HTML_TAGS = [
        // HTML5 elements
        'a' => true, 'abbr' => true, 'address' => true, 'area' => true,
        'article' => true, 'aside' => true, 'audio' => true,
        'b' => true, 'base' => true, 'bdi' => true, 'bdo' => true,
        'blockquote' => true, 'body' => true, 'br' => true, 'button' => true,
        'canvas' => true, 'caption' => true, 'cite' => true, 'code' => true,
        'col' => true, 'colgroup' => true,
        'data' => true, 'datalist' => true, 'dd' => true, 'del' => true,
        'details' => true, 'dfn' => true, 'dialog' => true, 'div' => true,
        'dl' => true, 'dt' => true,
        'em' => true, 'embed' => true,
        'fieldset' => true, 'figcaption' => true, 'figure' => true,
        'footer' => true, 'form' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true,
        'h5' => true, 'h6' => true, 'head' => true, 'header' => true,
        'hgroup' => true, 'hr' => true, 'html' => true,
        'i' => true, 'iframe' => true, 'img' => true, 'input' => true,
        'ins' => true,
        'kbd' => true,
        'label' => true, 'legend' => true, 'li' => true, 'link' => true,
        'main' => true, 'map' => true, 'mark' => true, 'menu' => true,
        'meta' => true, 'meter' => true,
        'nav' => true, 'noscript' => true,
        'object' => true, 'ol' => true, 'optgroup' => true, 'option' => true,
        'output' => true,
        'p' => true, 'picture' => true, 'pre' => true, 'progress' => true,
        'q' => true,
        'rp' => true, 'rt' => true, 'ruby' => true,
        's' => true, 'samp' => true, 'script' => true, 'search' => true,
        'section' => true, 'select' => true, 'small' => true, 'source' => true,
        'span' => true, 'strong' => true, 'style' => true, 'sub' => true,
        'summary' => true, 'sup' => true,
        'table' => true, 'tbody' => true, 'td' => true, 'template' => true,
        'textarea' => true, 'tfoot' => true, 'th' => true, 'thead' => true,
        'time' => true, 'title' => true, 'tr' => true, 'track' => true,
        'u' => true, 'ul' => true,
        'var' => true, 'video' => true,
        'wbr' => true,
        // SVG
        'svg' => true, 'path' => true, 'circle' => true, 'rect' => true,
        'line' => true, 'polygon' => true, 'polyline' => true,
        'ellipse' => true, 'g' => true, 'defs' => true, 'use' => true,
        'symbol' => true, 'tspan' => true, 'clippath' => true,
        'lineargradient' => true, 'radialgradient' => true, 'stop' => true,
        'mask' => true, 'pattern' => true, 'image' => true, 'foreignobject' => true,
        // MathML
        'math' => true, 'mrow' => true, 'mi' => true, 'mn' => true,
        'mo' => true, 'mfrac' => true, 'msup' => true, 'msub' => true,
    ];

    private string $source = '';
    private int $pos       = 0;
    private int $line      = 1;
    private int $length    = 0;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Tokenise the given template source and return an array of Token objects.
     *
     * Template comments <!-- … --> are stripped and never appear in the output.
     *
     * @return Token[]
     */
    public function tokenize(string $source): array
    {
        $this->source = $source;
        $this->length = strlen($source);
        $this->pos    = 0;
        $this->line   = 1;

        $tokens     = [];
        $textBuffer = '';
        $textLine   = 1;
        $textCol    = 1;

        while ($this->pos < $this->length) {
            // ----------------------------------------------------------------
            // Raw echo:  {!! ... !!}
            // ----------------------------------------------------------------
            if ($this->matchStr('{!!')) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(Token::T_TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens[] = $this->readRawEcho();
                $textLine = $this->line;
                $textCol  = $this->currentColumn();
                continue;
            }

            // ----------------------------------------------------------------
            // Escaped echo:  {{ ... }}
            // ----------------------------------------------------------------
            if ($this->matchStr('{{')) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(Token::T_TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens[] = $this->readEcho();
                $textLine = $this->line;
                $textCol  = $this->currentColumn();
                continue;
            }

            // ----------------------------------------------------------------
            // Directive:  #name  or  #name(expression)
            // ----------------------------------------------------------------
            if ($this->char() === '#'
                && $this->pos + 1 < $this->length
                && ctype_alpha($this->source[$this->pos + 1])
            ) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(Token::T_TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens[] = $this->readDirective();
                $textLine = $this->line;
                $textCol  = $this->currentColumn();
                continue;
            }

            // ----------------------------------------------------------------
            // Everything starting with '<' — ordered from most to least specific
            // ----------------------------------------------------------------
            if ($this->char() === '<') {
                // Template comment:  <!-- ... -->  — stripped completely
                if ($this->matchStr('<!--')) {
                    if ($textBuffer !== '') {
                        $tokens[]   = new Token(Token::T_TEXT, $textBuffer, $textLine, $textCol);
                        $textBuffer = '';
                    }
                    $this->skipComment();
                    $textLine = $this->line;
                    $textCol  = $this->currentColumn();
                    continue;
                }

                // Component close tag:  </Name>
                if ($this->pos + 2 < $this->length && $this->source[$this->pos + 1] === '/') {
                    $ahead = $this->peekTagName($this->pos + 2);
                    if ($ahead !== '' && ctype_alpha($ahead[0]) && !$this->isHtmlTag($ahead)) {
                        if ($textBuffer !== '') {
                            $tokens[]   = new Token(Token::T_TEXT, $textBuffer, $textLine, $textCol);
                            $textBuffer = '';
                        }
                        $tokens[] = $this->readComponentCloseTag();
                        $textLine = $this->line;
                        $textCol  = $this->currentColumn();
                        continue;
                    }
                }

                // Component open / self-closing tag:  <Name …>  or  <Name … />
                if ($this->pos + 1 < $this->length && ctype_alpha($this->source[$this->pos + 1])) {
                    $ahead = $this->peekTagName($this->pos + 1);
                    if ($ahead !== '' && !$this->isHtmlTag($ahead)) {
                        if ($textBuffer !== '') {
                            $tokens[]   = new Token(Token::T_TEXT, $textBuffer, $textLine, $textCol);
                            $textBuffer = '';
                        }
                        $tokens[] = $this->readComponentOpenTag();
                        $textLine = $this->line;
                        $textCol  = $this->currentColumn();
                        continue;
                    }
                }
            }

            // ----------------------------------------------------------------
            // Ordinary character — accumulate in the text buffer
            // ----------------------------------------------------------------
            $c = $this->source[$this->pos];
            if ($c === "\n") {
                $this->line++;
            }
            $textBuffer .= $c;
            $this->pos++;
        }

        if ($textBuffer !== '') {
            $tokens[] = new Token(Token::T_TEXT, $textBuffer, $textLine, $textCol);
        }

        return $tokens;
    }

    // -----------------------------------------------------------------------
    // Structural readers
    // -----------------------------------------------------------------------

    private function readEcho(): Token
    {
        $line = $this->line;
        $col  = $this->currentColumn();
        $this->pos += 2; // skip {{

        $expr  = '';
        $depth = 0;

        while ($this->pos < $this->length) {
            if ($this->matchStr('}}') && $depth === 0) {
                $this->pos += 2;

                return new Token(Token::T_ECHO, '{{ ' . trim($expr) . ' }}', $line, $col, null, trim($expr));
            }

            $c = $this->source[$this->pos];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            } elseif ($c === "\n") {
                $this->line++;
            }
            $expr .= $c;
            $this->pos++;
        }

        throw LexerException::unterminatedEcho($line);
    }

    private function readRawEcho(): Token
    {
        $line = $this->line;
        $col  = $this->currentColumn();
        $this->pos += 3; // skip {!!

        $expr = '';

        while ($this->pos < $this->length) {
            if ($this->matchStr('!!}')) {
                $this->pos += 3;

                return new Token(Token::T_RAW_ECHO, '{!! ' . trim($expr) . ' !!}', $line, $col, null, trim($expr));
            }

            $c = $this->source[$this->pos];
            if ($c === "\n") {
                $this->line++;
            }
            $expr .= $c;
            $this->pos++;
        }

        throw LexerException::unterminatedRawEcho($line);
    }

    private function readDirective(): Token
    {
        $line = $this->line;
        $col  = $this->currentColumn();
        $this->pos++; // skip #

        $name = '';
        while ($this->pos < $this->length
            && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')
        ) {
            $name .= $this->source[$this->pos];
            $this->pos++;
        }

        // Special: #php ... #endphp captures raw PHP code as a single token
        if ($name === 'php') {
            $raw = $this->readRawUntilEndDirective('endphp');

            return new Token(Token::T_PHP_BLOCK, '#php', $line, $col, 'php', $raw);
        }

        $expression = null;
        $this->skipInlineWhitespace();

        if ($this->pos < $this->length && $this->source[$this->pos] === '(') {
            $expression = $this->readParenthesizedExpression();
        }

        // Trailing colon is accepted but not required
        $this->skipInlineWhitespace();
        if ($this->pos < $this->length && $this->source[$this->pos] === ':') {
            $this->pos++;
        }

        $raw = '#' . $name . ($expression !== null ? '(' . $expression . ')' : '');

        return new Token(Token::T_DIRECTIVE, $raw, $line, $col, $name, $expression);
    }

    /**
     * Capture raw text until a closing directive marker (#endName) is found.
     *
     * Used by #php to collect raw PHP code without processing any {{ }}, {!! !!},
     * or nested #directives inside the block.
     *
     * The cursor must be positioned immediately after the opening directive name.
     * Leading whitespace / newline after #php is consumed before capturing content.
     * The closing marker and its optional trailing colon are consumed; the raw
     * content (trimmed of leading/trailing blank lines) is returned.
     */
    private function readRawUntilEndDirective(string $endName): string
    {
        // Skip optional trailing colon on the opening tag line
        $this->skipInlineWhitespace();
        if ($this->pos < $this->length && $this->source[$this->pos] === ':') {
            $this->pos++;
        }

        // Consume the rest of the opening tag line (newline included)
        while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
            $this->pos++;
        }
        if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
            $this->line++;
            $this->pos++;
        }

        $endTag    = '#' . $endName;
        $endTagLen = strlen($endTag);
        $content   = '';

        while ($this->pos < $this->length) {
            // Detect closing marker: #endName not followed by an alphanumeric char
            if ($this->matchStr($endTag)) {
                $after = $this->pos + $endTagLen;
                if ($after >= $this->length || !ctype_alnum($this->source[$after])) {
                    $this->pos += $endTagLen;

                    // Consume optional trailing colon
                    if ($this->pos < $this->length && $this->source[$this->pos] === ':') {
                        $this->pos++;
                    }

                    return rtrim($content);
                }
            }

            $c = $this->source[$this->pos];
            if ($c === "\n") {
                $this->line++;
            }
            $content .= $c;
            $this->pos++;
        }

        // Unterminated block — return whatever was captured
        return rtrim($content);
    }

    /**
     * Read the inner content of a balanced parenthesised expression.
     * Handles nested parens and quoted strings.
     * The opening '(' must be the current character.
     */
    private function readParenthesizedExpression(): string
    {
        $this->pos++; // skip (
        $depth      = 1;
        $expr       = '';
        $inString   = false;
        $stringChar = '';

        while ($this->pos < $this->length && $depth > 0) {
            $c = $this->source[$this->pos];

            if ($inString) {
                if ($c === '\\' && $this->pos + 1 < $this->length) {
                    $expr .= $c . $this->source[$this->pos + 1];
                    $this->pos += 2;
                    continue;
                }
                if ($c === $stringChar) {
                    $inString = false;
                }
                $expr .= $c;
                $this->pos++;
                continue;
            }

            if ($c === '"' || $c === "'") {
                $inString   = true;
                $stringChar = $c;
                $expr .= $c;
                $this->pos++;
                continue;
            }

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $this->pos++;
                    break;
                }
            }

            if ($c === "\n") {
                $this->line++;
            }
            $expr .= $c;
            $this->pos++;
        }

        return $expr;
    }

    private function readComponentOpenTag(): Token
    {
        $line = $this->line;
        $col  = $this->currentColumn();
        $this->pos++; // skip <

        $name  = $this->readTagName();
        $props = $this->readProps();

        $this->skipWhitespace();

        if ($this->matchStr('/>')) {
            $this->pos += 2;

            return new Token(Token::T_COMPONENT_SELF, '<' . $name . ' />', $line, $col, $name, null, $props);
        }

        if ($this->pos < $this->length && $this->source[$this->pos] === '>') {
            $this->pos++;
        }

        return new Token(Token::T_COMPONENT_OPEN, '<' . $name . '>', $line, $col, $name, null, $props);
    }

    private function readComponentCloseTag(): Token
    {
        $line = $this->line;
        $col  = $this->currentColumn();
        $this->pos += 2; // skip </

        $name = $this->readTagName();

        $this->skipWhitespace();
        if ($this->pos < $this->length && $this->source[$this->pos] === '>') {
            $this->pos++;
        }

        return new Token(Token::T_COMPONENT_CLOSE, '</' . $name . '>', $line, $col, $name);
    }

    /**
     * Skip past a <!-- ... --> comment.
     * The '<!--' prefix must already have been matched.
     */
    private function skipComment(): void
    {
        $this->pos += 4; // skip <!--

        while ($this->pos < $this->length) {
            if ($this->matchStr('-->')) {
                $this->pos += 3;

                return;
            }
            if ($this->source[$this->pos] === "\n") {
                $this->line++;
            }
            $this->pos++;
        }
        // Unterminated comment — silently consumed
    }

    // -----------------------------------------------------------------------
    // Prop parsing
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{type: string, value: mixed}>
     */
    private function readProps(): array
    {
        $props = [];

        while ($this->pos < $this->length) {
            $this->skipWhitespace();

            if ($this->pos >= $this->length
                || $this->source[$this->pos] === '>'
                || $this->matchStr('/>')) {
                break;
            }

            $propName = $this->readPropName();
            if ($propName === '') {
                $this->pos++;
                continue;
            }

            // Detect dynamic binding prefix `:propName`
            $isDynamic = str_starts_with($propName, ':');
            if ($isDynamic) {
                $propName = substr($propName, 1);
            }

            $this->skipInlineWhitespace();

            if ($this->pos < $this->length && $this->source[$this->pos] === '=') {
                $this->pos++;
                $this->skipInlineWhitespace();
                $propValue = $this->readPropValue();

                // `:prop="expr"` — the quoted string IS a PHP expression
                if ($isDynamic && $propValue['type'] === 'literal') {
                    $propValue = ['type' => 'expression', 'value' => $propValue['value']];
                }

                $props[$propName] = $propValue;
            } else {
                $props[$propName] = ['type' => 'boolean', 'value' => true];
            }
        }

        return $props;
    }

    private function readPropName(): string
    {
        $name = '';
        while ($this->pos < $this->length
            && (ctype_alnum($this->source[$this->pos])
                || $this->source[$this->pos] === '-'
                || $this->source[$this->pos] === '_'
                || $this->source[$this->pos] === ':')
        ) {
            $name .= $this->source[$this->pos];
            $this->pos++;
        }

        return $name;
    }

    /**
     * @return array{type: string, value: mixed}
     */
    private function readPropValue(): array
    {
        if ($this->pos >= $this->length) {
            return ['type' => 'literal', 'value' => ''];
        }

        $c = $this->source[$this->pos];

        // Quoted string:  "hello"  or  'hello'
        if ($c === '"' || $c === "'") {
            $quote = $c;
            $this->pos++;
            $value = '';

            while ($this->pos < $this->length && $this->source[$this->pos] !== $quote) {
                if ($this->source[$this->pos] === '\\' && $this->pos + 1 < $this->length) {
                    $this->pos++;
                    $value .= $this->source[$this->pos];
                } else {
                    $value .= $this->source[$this->pos];
                }
                $this->pos++;
            }
            if ($this->pos < $this->length) {
                $this->pos++;
            }

            return ['type' => 'literal', 'value' => $value];
        }

        // PHP expression:  { $var }  or  { some_func() }
        if ($c === '{') {
            $this->pos++;
            $depth = 1;
            $expr  = '';

            while ($this->pos < $this->length && $depth > 0) {
                $ch = $this->source[$this->pos];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $this->pos++;
                        break;
                    }
                }
                $expr .= $ch;
                $this->pos++;
            }

            return ['type' => 'expression', 'value' => trim($expr)];
        }

        // Unquoted bare value
        $value = '';
        while ($this->pos < $this->length
            && !ctype_space($this->source[$this->pos])
            && $this->source[$this->pos] !== '>'
            && !$this->matchStr('/>')
        ) {
            $value .= $this->source[$this->pos];
            $this->pos++;
        }

        return ['type' => 'literal', 'value' => $value];
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    private function readTagName(): string
    {
        $name = '';
        while ($this->pos < $this->length
            && (ctype_alnum($this->source[$this->pos])
                || $this->source[$this->pos] === '-'
                || $this->source[$this->pos] === '_'
                || $this->source[$this->pos] === '.')
        ) {
            $name .= $this->source[$this->pos];
            $this->pos++;
        }

        return $name;
    }

    /**
     * Look ahead from $startPos and return the tag name without advancing $this->pos.
     */
    private function peekTagName(int $startPos): string
    {
        $name = '';
        $pos  = $startPos;

        while ($pos < $this->length
            && (ctype_alnum($this->source[$pos])
                || $this->source[$pos] === '-'
                || $this->source[$pos] === '_'
                || $this->source[$pos] === '.')
        ) {
            $name .= $this->source[$pos];
            $pos++;
        }

        return $name;
    }

    /**
     * Returns true when $name is a known HTML5 / SVG / MathML element.
     */
    private function isHtmlTag(string $name): bool
    {
        return isset(self::HTML_TAGS[strtolower($name)]);
    }

    private function char(): string
    {
        return $this->source[$this->pos];
    }

    private function matchStr(string $str): bool
    {
        $len = strlen($str);
        if ($this->pos + $len > $this->length) {
            return false;
        }

        return substr($this->source, $this->pos, $len) === $str;
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && ctype_space($this->source[$this->pos])) {
            if ($this->source[$this->pos] === "\n") {
                $this->line++;
            }
            $this->pos++;
        }
    }

    private function skipInlineWhitespace(): void
    {
        while ($this->pos < $this->length
            && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
        ) {
            $this->pos++;
        }
    }

    /**
     * Return the 1-based column number of the current position by counting
     * backwards to the last newline (or start of source).
     */
    private function currentColumn(): int
    {
        $p = $this->pos;

        while ($p > 0 && $this->source[$p - 1] !== "\n") {
            $p--;
        }

        return $this->pos - $p + 1;
    }
}
