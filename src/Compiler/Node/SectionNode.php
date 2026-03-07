<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #section('name') / #endsection block.
 *
 * At runtime the content of the section is captured by SectionManager
 * and made available to parent layouts via YieldNode.
 */
final class SectionNode extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        public readonly string $name,
        public readonly array $children,
    ) {
    }

    public function compile(): string
    {
        $out  = '<?php $__env->startSection(\'' . addslashes($this->name) . '\'); ?>';
        $out .= $this->compileChildren($this->children);
        $out .= '<?php $__env->endSection(); ?>';

        return $out;
    }

    /** @return Node[] */
    public function getChildren(): array
    {
        return $this->children;
    }
}
