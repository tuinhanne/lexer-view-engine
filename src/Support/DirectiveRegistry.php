<?php

declare(strict_types=1);

namespace Wik\Lexer\Support;

/**
 * Holds user-registered custom directives.
 *
 * A directive handler is a callable that receives the directive's expression
 * (the content inside the parentheses) and returns a PHP string to be embedded
 * verbatim in the compiled template.
 *
 * Example registration:
 *   $registry->register('datetime', fn(string $expr) => "<?php echo date('Y-m-d H:i:s', {$expr}); ?>");
 *
 * Example usage in template:
 *   #datetime(time())
 */
final class DirectiveRegistry
{
    /** @var array<string, callable(string): string> */
    private array $directives = [];

    /**
     * Register a custom directive handler.
     *
     * @param callable(string): string $handler  Receives the expression, returns compiled PHP
     */
    public function register(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }

    /**
     * Check whether a directive with the given name has been registered.
     */
    public function has(string $name): bool
    {
        return isset($this->directives[$name]);
    }

    /**
     * Compile a directive by calling its registered handler.
     *
     * @throws \InvalidArgumentException  if the directive is not registered
     */
    public function compile(string $name, string $expression): string
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException("Directive '{$name}' is not registered.");
        }

        return ($this->directives[$name])($expression);
    }

    /**
     * Return all registered directive names.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->directives);
    }
}
