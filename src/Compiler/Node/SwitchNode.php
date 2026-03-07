<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a #switch / #case / #default / #endswitch block.
 *
 * Template syntax:
 *   #switch ($status):
 *     #case ('active'):
 *       <span>Active</span>
 *     #break
 *
 *     #case ('pending'):
 *       <span>Pending</span>
 *     #break
 *
 *     #default:
 *       <span>Unknown</span>
 *   #endswitch
 *
 * Compiled output:
 *   <?php switch ($status): ?>
 *   <?php case 'active': ?>
 *     <span>Active</span>
 *   <?php break; ?>
 *   <?php case 'pending': ?>
 *     <span>Pending</span>
 *   <?php break; ?>
 *   <?php default: ?>
 *     <span>Unknown</span>
 *   <?php endswitch; ?>
 *
 * Notes:
 *   - $value is null for the #default branch.
 *   - Children for each case include any #break / #continue nodes.
 *   - Fall-through is supported: omit #break between cases.
 */
final class SwitchNode extends Node
{
    /**
     * @param array<int, array{value: string|null, children: Node[]}> $cases
     *   Each entry represents one #case or #default branch.
     *   'value' is the raw PHP expression passed to #case, or null for #default.
     */
    public function __construct(
        public readonly string $expression,
        public readonly array $cases,
    ) {
    }

    public function compile(): string
    {
        $out = '<?php switch (' . $this->expression . '): ?>';

        foreach ($this->cases as $case) {
            if ($case['value'] === null) {
                $out .= '<?php default: ?>';
            } else {
                $out .= '<?php case ' . $case['value'] . ': ?>';
            }

            $out .= $this->compileChildren($case['children']);
        }

        $out .= '<?php endswitch; ?>';

        return $out;
    }

    /** @return Node[] */
    public function getChildren(): array
    {
        $all = [];

        foreach ($this->cases as $case) {
            foreach ($case['children'] as $child) {
                $all[] = $child;
            }
        }

        return $all;
    }
}
