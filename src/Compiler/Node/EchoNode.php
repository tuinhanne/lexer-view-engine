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
        if ($this->raw) {
            return '<?php echo ' . $this->expression . '; ?>';
        }

        // Route through the per-render escaper so custom EscaperInterface
        // implementations are respected at runtime.
        return '<?php echo $__env->escape(' . $this->expression . '); ?>';
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
