<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents an echo expression.
 *
 * Escaped form:  {{ $expression }}
 *   Calls $__env->escape() which delegates to the configured EscaperInterface.
 *   Custom escapers are injected via $engine->setEscaper().
 *
 * Raw form:      {!! $expression !!}
 *   Outputs the expression without escaping. Forbidden in sandbox mode.
 */
final class EchoNode extends Node
{
    public function __construct(
        public readonly string $expression,
        public readonly bool $raw = false,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        $expr = $this->normalizeExpression($this->expression);

        if ($this->raw) {
            return '<?php echo ' . $expr . '; ?>';
        }

        // Route through the per-render escaper so custom EscaperInterface
        // implementations are respected at runtime.
        return '<?php echo $__env->escape(' . $expr . '); ?>';
    }

    /**
     * Auto-prefix bare identifiers with `$` so that `{{ title }}` compiles
     * the same as `{{ $title }}` instead of being treated as a PHP constant.
     */
    private function normalizeExpression(string $expr): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $expr)) {
            return '$' . $expr;
        }

        return $expr;
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
