<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents the #parent directive used inside a #section block.
 *
 * Outputs the parent layout's version of the current section.  This allows
 * child templates to extend (prepend / append to) an inherited section rather
 * than fully replacing it.
 *
 * Example:
 *   #section('sidebar')
 *     #parent
 *     <div class="extra">My extra content</div>
 *   #endsection
 *
 * At runtime, $__env->parentSection() retrieves whatever content the parent
 * layout previously stored for the same section name.
 */
final class ParentNode extends Node
{
    public function __construct(
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        return '<?php echo $__env->parentSection(); ?>';
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
