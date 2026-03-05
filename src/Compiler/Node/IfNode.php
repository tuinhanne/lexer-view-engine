<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents an #if / #elseif / #else / #endif block.
 *
 * Structure:
 *   condition    — PHP expression for the primary #if
 *   children     — nodes inside the primary #if branch
 *   elseifBranches — array of ['condition' => string, 'children' => Node[]]
 *   elseChildren — nodes inside the #else branch, or null if absent
 */
final class IfNode extends Node
{
    /**
     * @param Node[]                                          $children
     * @param array<int, array{condition: string, children: Node[]}> $elseifBranches
     * @param Node[]|null                                     $elseChildren
     */
    public function __construct(
        public readonly string $condition,
        public readonly array $children,
        public readonly array $elseifBranches = [],
        public readonly ?array $elseChildren = null,
    ) {
    }

    public function compile(): string
    {
        $out = '<?php if (' . $this->condition . '): ?>';
        $out .= $this->compileChildren($this->children);

        foreach ($this->elseifBranches as $branch) {
            $out .= '<?php elseif (' . $branch['condition'] . '): ?>';
            $out .= $this->compileChildren($branch['children']);
        }

        if ($this->elseChildren !== null) {
            $out .= '<?php else: ?>';
            $out .= $this->compileChildren($this->elseChildren);
        }

        $out .= '<?php endif; ?>';

        return $out;
    }
}
