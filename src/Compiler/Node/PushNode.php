<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #push('stack-name') / #endpush block.
 *
 * Push nodes APPEND content to a named stack rather than replacing it.
 * Multiple templates can push to the same stack; all content is concatenated
 * in the order the pushes were executed.
 *
 * Layout templates retrieve the accumulated stack content via #stack('name').
 *
 * Compiled output calls $__env->startPush() / $__env->endPush() at runtime.
 */
final class PushNode extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        public readonly string $name,
        public readonly array $children,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        $escaped = addslashes($this->name);
        $out     = "<?php \$__env->startPush('{$escaped}'); ?>";
        $out    .= $this->compileChildren($this->children);
        $out    .= '<?php $__env->endPush(); ?>';

        return $out;
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
