<?php

declare(strict_types=1);

namespace Wik\Lexer\Security;

use Wik\Lexer\Contracts\EscaperInterface;

/**
 * Default HTML escaper using PHP's built-in htmlspecialchars.
 *
 * Converts the five HTML-sensitive characters to their named entity equivalents:
 *   &  →  &amp;
 *   <  →  &lt;
 *   >  →  &gt;
 *   "  →  &quot;
 *   '  →  &#039;
 *
 * Objects implementing \Stringable are cast to string before escaping.
 * All other scalar types are coerced to string.
 * Null, arrays, and other non-scalar types produce an empty string.
 */
final class HtmlEscaper implements EscaperInterface
{
    public function __construct(
        private readonly int $flags = ENT_QUOTES | ENT_SUBSTITUTE,
        private readonly string $encoding = 'UTF-8',
    ) {
    }

    public function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, $this->flags, $this->encoding);
    }
}
