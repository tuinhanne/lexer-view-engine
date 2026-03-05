<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a custom (user-registered) directive.
 *
 * The compiled PHP output is resolved at parse time via the DirectiveRegistry
 * and stored directly in this node so that compile() requires no external
 * context and remains a simple string return.
 */
final class DirectiveNode extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $expression,
        public readonly string $compiledOutput,
        public readonly int $line,
    ) {
    }

    public function compile(): string
    {
        return $this->compiledOutput;
    }
}
