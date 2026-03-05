<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents an #extends('layout') declaration.
 *
 * This node must appear before any sections in a template that inherits
 * from a parent layout.  At runtime it tells the Environment which layout
 * to render after the child template finishes registering its sections.
 */
final class ExtendsNode extends Node
{
    public function __construct(
        public readonly string $layout,
    ) {
    }

    public function compile(): string
    {
        return '<?php $__env->extend(\'' . addslashes($this->layout) . '\'); ?>';
    }
}
