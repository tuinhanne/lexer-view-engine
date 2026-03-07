<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents a component tag — self-closing or with slot content.
 *
 * Self-closing:           <Card title="Hello" />
 * With default slot:      <Card title="Hello"> ... </Card>
 * With named slots:       <Card> <slot name="header">H1</slot> Body </Card>
 *
 * Dynamic prop binding (`:` prefix):
 *   <Card :title="$post->title" />
 *   The `:title` prop is treated as a PHP expression, not a literal string.
 *
 * Props use the shape produced by the Lexer:
 *   ['type' => 'literal',    'value' => 'string']
 *   ['type' => 'expression', 'value' => '$phpExpr']
 *   ['type' => 'boolean',    'value' => true]
 *
 * Named slots are child ComponentNode instances with name === 'slot'.
 * They compile to $__env->startSlot() / $__env->endSlot() calls which
 * partition their content away from the default ob_start() buffer.
 */
final class ComponentNode extends Node
{
    /**
     * @param array<string, array{type: string, value: mixed}> $props
     * @param Node[]                                           $children  Slot content
     */
    public function __construct(
        public readonly string $name,
        public readonly array $props,
        public readonly array $children,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        $safeName  = addslashes($this->name);
        $propsExpr = $this->compilePropsArray();

        if (empty($this->children)) {
            // Self-closing: render immediately with no slot content
            return "<?php echo \$__env->renderComponent('{$safeName}', {$propsExpr}, []); ?>";
        }

        // Partition children: named slot nodes vs default slot content
        $out = "<?php \$__env->startComponent('{$safeName}', {$propsExpr}); ?>";

        foreach ($this->children as $child) {
            if ($child instanceof self && $child->name === 'slot') {
                // Named slot: extract slot name from props
                $slotNameProp = $child->props['name'] ?? ['type' => 'literal', 'value' => 'default'];
                $slotName     = addslashes((string) $slotNameProp['value']);

                $out .= "<?php \$__env->startSlot('{$slotName}'); ?>";
                $out .= $this->compileChildren($child->children);
                $out .= '<?php $__env->endSlot(); ?>';
            } else {
                $out .= $child->compile();
            }
        }

        $out .= '<?php echo $__env->endComponent(); ?>';

        return $out;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    /** @return Node[] */
    public function getChildren(): array
    {
        return $this->children;
    }

    // -----------------------------------------------------------------------
    // Props compilation
    // -----------------------------------------------------------------------

    private function compilePropsArray(): string
    {
        if (empty($this->props)) {
            return '[]';
        }

        $pairs = [];

        foreach ($this->props as $key => $prop) {
            $compiledKey   = "'" . addslashes($key) . "'";
            $compiledValue = $this->compilePropValue($prop);
            $pairs[]       = $compiledKey . ' => ' . $compiledValue;
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /**
     * @param array{type: string, value: mixed} $prop
     */
    private function compilePropValue(array $prop): string
    {
        return match ($prop['type']) {
            'expression' => (string) $prop['value'],
            'boolean'    => $prop['value'] ? 'true' : 'false',
            default      => "'" . addslashes((string) $prop['value']) . "'",
        };
    }
}
