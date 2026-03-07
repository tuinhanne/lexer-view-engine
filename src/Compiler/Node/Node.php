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

    /**
     * Return all direct child nodes across every branch of this node.
     *
     * Container nodes (IfNode, ForEachNode, SectionNode, ComponentNode, …)
     * override this to expose their children for recursive AST traversal —
     * for example, static dependency extraction in the Compiler.
     *
     * Leaf nodes (TextNode, EchoNode, DirectiveNode, …) keep the default
     * empty-array return value.
     *
     * @return Node[]
     */
    public function getChildren(): array
    {
        return [];
    }
}
