<?php

declare(strict_types=1);

namespace Wik\Lexer\Contracts;

use Wik\Lexer\Compiler\Node\Node;
use Wik\Lexer\Compiler\Token;

/**
 * Template compiler contract.
 *
 * A compiler transforms a template source string into an executable PHP file
 * via the lex → tokenise → parse → validate → optimise → emit pipeline.
 */
interface CompilerInterface
{
    /**
     * Compile a template source string.
     *
     * Returns the absolute path to the compiled PHP file that can be
     * safely include()'d to render the template.
     */
    public function compile(string $source, string $templatePath): string;

    /**
     * Force a full recompile, bypassing any cached results.
     */
    public function recompile(string $source, string $templatePath): string;

    /**
     * Parse a source string and return the raw AST.
     *
     * @return Node[]
     */
    public function parse(string $source): array;

    /**
     * Tokenise a source string and return the flat Token stream.
     *
     * @return Token[]
     */
    public function tokenize(string $source): array;
}
