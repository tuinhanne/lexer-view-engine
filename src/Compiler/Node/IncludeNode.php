<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler\Node;

/**
 * Represents an #include / #includeIf / #includeWhen / #includeFirst directive.
 *
 * Template syntax:
 *   #include('partials.header')
 *   #include('partials.nav', ['user' => $user])
 *   #includeIf('partials.sidebar')
 *   #includeWhen($isAdmin, 'partials.admin')
 *   #includeFirst(['custom.nav', 'nav'])
 *
 * Compiled output delegates to the corresponding $__env method:
 *   <?php echo $__env->include('partials.header'); ?>
 */
final class IncludeNode extends Node
{
    /**
     * @param string $method     One of: include, includeIf, includeWhen, includeFirst
     * @param string $expression Raw expression passed as argument(s) to the method
     */
    public function __construct(
        public readonly string $method,
        public readonly string $expression,
        private readonly int $line = 0,
    ) {
    }

    public function compile(): string
    {
        return '<?php echo $__env->' . $this->method . '(' . $this->expression . '); ?>';
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
