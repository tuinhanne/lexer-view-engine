<?php

declare(strict_types=1);

namespace Wik\Lexer\Exceptions;

/**
 * Thrown when an error occurs during template execution (rendering).
 *
 * Unlike TemplateSyntaxException (compile-time), this exception is raised
 * when the compiled template PHP code is actually running — e.g. infinite
 * layout loops, component recursion limits, missing component files, etc.
 */
final class TemplateRuntimeException extends LexException
{
    public function __construct(
        string $message,
        public readonly string $templateFile = '',
        public readonly int $templateLine = 0,
        ?\Throwable $previous = null,
    ) {
        $location = $templateFile !== '' ? " in {$templateFile}" : '';

        if ($templateLine > 0) {
            $location .= " on line {$templateLine}";
        }

        parent::__construct($message . $location, 0, $previous);
    }

    // -----------------------------------------------------------------------
    // Named constructors
    // -----------------------------------------------------------------------

    public static function infiniteLayoutLoop(string $path): self
    {
        return new self(
            "Infinite layout loop detected — '{$path}' is already being rendered."
        );
    }

    public static function componentRecursionLimit(string $name, int $limit): self
    {
        return new self(
            "Component recursion limit ({$limit}) exceeded for component '{$name}'."
        );
    }

    public static function sandboxRawEchoForbidden(string $file = '', int $line = 0): self
    {
        return new self(
            'Raw echo ({!! ... !!}) is forbidden in sandbox mode.',
            $file,
            $line,
        );
    }

    public static function fromPhpError(
        \Throwable $e,
        string $templateFile,
        int $compiledLine,
    ): self {
        return new self(
            sprintf(
                'PHP error during template rendering: %s (compiled line %d)',
                $e->getMessage(),
                $compiledLine,
            ),
            $templateFile,
            0,
            $e,
        );
    }
}
