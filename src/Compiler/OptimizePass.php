<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler;

use Wik\Lexer\Compiler\Node\ComponentNode;
use Wik\Lexer\Compiler\Node\EchoNode;
use Wik\Lexer\Compiler\Node\ForEachNode;
use Wik\Lexer\Compiler\Node\IfNode;
use Wik\Lexer\Compiler\Node\Node;
use Wik\Lexer\Compiler\Node\PushNode;
use Wik\Lexer\Compiler\Node\SectionNode;
use Wik\Lexer\Compiler\Node\SwitchNode;
use Wik\Lexer\Compiler\Node\TextNode;
use Wik\Lexer\Compiler\Node\WhileNode;

/**
 * AST optimisation pass — runs after validation, before code generation.
 *
 * Current optimisations:
 *   1. Merge adjacent TextNode siblings into a single TextNode.
 *   2. Remove empty TextNode instances (whitespace-only nodes between blocks).
 *   3. Recursively apply to all container nodes.
 *
 * Planned (not yet enabled by default):
 *   - Inline simple, constant echo expressions
 *   - Eliminate unreachable branches (e.g. #if(false))
 */
final class OptimizePass
{
    public function __construct(
        private readonly bool $mergeTextNodes = true,
        private readonly bool $removeEmptyTextNodes = true,
    ) {
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Optimise an AST and return the transformed node array.
     *
     * @param  Node[] $nodes
     * @return Node[]
     */
    public function optimize(array $nodes): array
    {
        // First, recurse into container children
        $processed = $this->processNodes($nodes);

        // Then apply flat optimisations on the resulting list
        if ($this->mergeTextNodes) {
            $processed = $this->mergeAdjacentTextNodes($processed);
        }

        if ($this->removeEmptyTextNodes) {
            $processed = $this->removeEmptyTextNodes($processed);
        }

        return $processed;
    }

    // -----------------------------------------------------------------------
    // Recursive container processing
    // -----------------------------------------------------------------------

    /**
     * @param  Node[] $nodes
     * @return Node[]
     */
    private function processNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $result[] = $this->processNode($node);
        }

        return $result;
    }

    private function processNode(Node $node): Node
    {
        return match (true) {
            $node instanceof IfNode       => $this->processIfNode($node),
            $node instanceof ForEachNode  => new ForEachNode(
                $node->expression,
                $this->optimize($node->children),
            ),
            $node instanceof WhileNode    => new WhileNode(
                $node->condition,
                $this->optimize($node->children),
            ),
            $node instanceof SwitchNode   => $this->processSwitchNode($node),
            $node instanceof SectionNode  => new SectionNode(
                $node->name,
                $this->optimize($node->children),
            ),
            $node instanceof ComponentNode => new ComponentNode(
                $node->name,
                $node->props,
                $this->optimize($node->children),
                $node->getLine(),
            ),
            $node instanceof PushNode     => new PushNode(
                $node->name,
                $this->optimize($node->children),
                $node->getLine(),
            ),
            default => $node,
        };
    }

    private function processIfNode(IfNode $node): IfNode
    {
        $optimizedElseif = array_map(
            fn(array $branch) => [
                'condition' => $branch['condition'],
                'children'  => $this->optimize($branch['children']),
            ],
            $node->elseifBranches,
        );

        $optimizedElse = $node->elseChildren !== null
            ? $this->optimize($node->elseChildren)
            : null;

        return new IfNode(
            $node->condition,
            $this->optimize($node->children),
            $optimizedElseif,
            $optimizedElse,
        );
    }

    private function processSwitchNode(SwitchNode $node): SwitchNode
    {
        $optimizedCases = array_map(
            fn(array $case) => [
                'value'    => $case['value'],
                'children' => $this->optimize($case['children']),
            ],
            $node->cases,
        );

        return new SwitchNode($node->expression, $optimizedCases);
    }

    // -----------------------------------------------------------------------
    // Flat list optimisations
    // -----------------------------------------------------------------------

    /**
     * Merge sequences of adjacent TextNodes into a single TextNode.
     *
     * @param  Node[] $nodes
     * @return Node[]
     */
    private function mergeAdjacentTextNodes(array $nodes): array
    {
        $result      = [];
        $textBuffer  = '';
        $textLine    = 0;

        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                if ($textLine === 0) {
                    $textLine = $node->getLine();
                }
                $textBuffer .= $node->text;
            } else {
                if ($textBuffer !== '') {
                    $result[]   = new TextNode($textBuffer, $textLine);
                    $textBuffer = '';
                    $textLine   = 0;
                }
                $result[] = $node;
            }
        }

        if ($textBuffer !== '') {
            $result[] = new TextNode($textBuffer, $textLine);
        }

        return $result;
    }

    /**
     * Remove TextNodes that contain only whitespace characters.
     *
     * This reduces clutter in compiled output between block directives.
     *
     * @param  Node[] $nodes
     * @return Node[]
     */
    private function removeEmptyTextNodes(array $nodes): array
    {
        return array_values(array_filter(
            $nodes,
            fn(Node $n) => !($n instanceof TextNode) || trim($n->text) !== '',
        ));
    }
}
