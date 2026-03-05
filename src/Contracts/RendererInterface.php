<?php

declare(strict_types=1);

namespace Wik\Lexer\Contracts;

/**
 * Template renderer contract.
 *
 * A renderer resolves a template name (or absolute path) to HTML, executing
 * the compiled PHP in an isolated scope with the provided data variables.
 */
interface RendererInterface
{
    /**
     * Render a named template and return the resulting HTML.
     *
     * @param  array<string, mixed> $data  Variables injected into the template scope
     */
    public function render(string $name, array $data = []): string;

    /**
     * Render a template by absolute file path and return the resulting HTML.
     *
     * @param  array<string, mixed> $data
     */
    public function renderFile(string $filePath, array $data = []): string;
}
