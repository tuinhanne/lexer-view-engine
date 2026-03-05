<?php

declare(strict_types=1);

namespace Wik\Lexer\Contracts;

/**
 * Defines the contract for HTML-escaping output values.
 *
 * The default implementation uses htmlspecialchars with UTF-8.
 * Implement this interface and call $engine->setEscaper() to replace the
 * default strategy (e.g. to integrate with a framework's XSS filter).
 */
interface EscaperInterface
{
    /**
     * Escape a value for safe HTML output.
     *
     * The returned string must be safe to place verbatim inside an HTML document.
     */
    public function escape(mixed $value): string;
}
