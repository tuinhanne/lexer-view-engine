<?php

declare(strict_types=1);

namespace Wik\Lexer\Exceptions;

/**
 * Thrown when a template contains a syntax or structural error that is
 * detected at compile time (lexing, parsing, or AST validation).
 *
 * The exception carries precise source location information so that
 * developer tools can display a useful error message with a code snippet.
 */
final class TemplateSyntaxException extends LexException
{
    public function __construct(
        string $message,
        public readonly string $templateFile = '',
        public readonly int $templateLine = 0,
        public readonly int $templateColumn = 0,
        public readonly string $snippet = '',
        ?\Throwable $previous = null,
    ) {
        $location = $templateFile !== '' ? " in {$templateFile}" : '';

        if ($templateLine > 0) {
            $location .= " on line {$templateLine}";
            if ($templateColumn > 0) {
                $location .= ", column {$templateColumn}";
            }
        }

        $full = $message . $location;

        if ($snippet !== '') {
            $full .= "\n\n" . $snippet;
        }

        parent::__construct($full, 0, $previous);
    }

    // -----------------------------------------------------------------------
    // Named constructors
    // -----------------------------------------------------------------------

    public static function atLocation(
        string $message,
        string $file,
        int $line,
        int $column,
        string $source = '',
    ): self {
        $snippet = $source !== '' ? self::extractSnippet($source, $line) : '';

        return new self($message, $file, $line, $column, $snippet);
    }

    public static function unterminatedBlock(
        string $blockType,
        string $file,
        int $line,
    ): self {
        return new self(
            "Unclosed {$blockType} block — add the matching closing directive.",
            $file,
            $line,
        );
    }

    public static function mismatchedBlock(
        string $expected,
        string $actual,
        string $file,
        int $line,
    ): self {
        return new self(
            "Mismatched closing tag: expected </{$expected}>, got </{$actual}>.",
            $file,
            $line,
        );
    }

    public static function duplicateSection(
        string $name,
        string $file,
        int $line,
    ): self {
        return new self(
            "Duplicate section definition: section '{$name}' is already defined.",
            $file,
            $line,
        );
    }

    public static function sandboxViolation(
        string $detail,
        string $file = '',
        int $line = 0,
    ): self {
        return new self("Sandbox violation: {$detail}", $file, $line);
    }

    public static function invalidExpression(
        string $expression,
        string $reason,
        string $file = '',
        int $line = 0,
    ): self {
        $short = strlen($expression) > 60 ? substr($expression, 0, 60) . '…' : $expression;

        return new self(
            "Invalid expression ({$reason}): `{$short}`",
            $file,
            $line,
        );
    }

    // -----------------------------------------------------------------------
    // Snippet extraction helper
    // -----------------------------------------------------------------------

    private static function extractSnippet(string $source, int $errorLine): string
    {
        $lines  = explode("\n", $source);
        $total  = count($lines);
        $start  = max(1, $errorLine - 2);
        $end    = min($total, $errorLine + 2);
        $output = '';

        for ($i = $start; $i <= $end; $i++) {
            $marker  = $i === $errorLine ? '  --> ' : '      ';
            $lineStr = str_pad((string) $i, 4, ' ', STR_PAD_LEFT);
            $output .= $marker . $lineStr . ' | ' . ($lines[$i - 1] ?? '') . "\n";
        }

        return $output;
    }
}
