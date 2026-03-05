<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler;

/**
 * Represents a single lexical token produced by the Lexer.
 *
 * Props array entries use the shape:
 *   ['type' => 'literal',    'value' => 'string value']
 *   ['type' => 'expression', 'value' => '$phpExpr']
 *   ['type' => 'boolean',    'value' => true]
 */
final class Token
{
    /** Raw text content (HTML, whitespace, etc.) */
    public const T_TEXT = 'T_TEXT';

    /** Escaped echo: {{ expression }} */
    public const T_ECHO = 'T_ECHO';

    /** Raw echo: {!! expression !!} */
    public const T_RAW_ECHO = 'T_RAW_ECHO';

    /** Directive: #name or #name(expression) */
    public const T_DIRECTIVE = 'T_DIRECTIVE';

    /** Component opening tag: <ComponentName props> */
    public const T_COMPONENT_OPEN = 'T_COMPONENT_OPEN';

    /** Component closing tag: </ComponentName> */
    public const T_COMPONENT_CLOSE = 'T_COMPONENT_CLOSE';

    /** Self-closing component tag: <ComponentName props /> */
    public const T_COMPONENT_SELF = 'T_COMPONENT_SELF';

    /** Raw PHP block: #php ... #endphp */
    public const T_PHP_BLOCK = 'T_PHP_BLOCK';

    /**
     * @param string      $type       One of the T_* constants
     * @param string      $value      Raw source representation
     * @param int         $line       1-based source line number
     * @param int         $column     1-based source column number
     * @param string|null $name       Directive name or component name
     * @param string|null $expression Directive expression (contents of parentheses)
     * @param array       $props      Component props: [name => ['type'=>..., 'value'=>...]]
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $line,
        public readonly int $column = 1,
        public readonly ?string $name = null,
        public readonly ?string $expression = null,
        public readonly array $props = [],
    ) {
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function __toString(): string
    {
        return sprintf(
            'Token(%s, line=%d, col=%d, value=%s)',
            $this->type,
            $this->line,
            $this->column,
            substr($this->value, 0, 30),
        );
    }
}
