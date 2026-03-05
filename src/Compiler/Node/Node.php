<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Abstract base class for all AST nodes.
 *
 * Every node is responsible for compiling itself into a PHP string fragment.
 * Container nodes (IfNode, ForEachNode, ComponentNode, …) compile their
 * children recursively via the compileChildren() helper.
 */
abstract class Node
{
    /**
     * Compile this node into a PHP source fragment.
     */
    abstract public function compile(): string;

    /**
     * Compile an ordered array of child nodes into a concatenated PHP string.
     *
     * Declared public so that nodes which wrap other nodes (e.g. ComponentNode
     * compiling slot children) can call it for arbitrary child arrays.
     *
     * @param Node[] $children
     */
    public function compileChildren(array $children): string
    {
        $output = '';

        foreach ($children as $child) {
            $output .= $child->compile();
        }

        return $output;
    }

    /**
     * Return the source line this node was created from, for error reporting.
     * Nodes that store a line number should override this.
     */
    public function getLine(): int
    {
        return 0;
    }
}
