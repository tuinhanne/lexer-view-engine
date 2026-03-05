<?php

declare(strict_types=1);

namespace Wik\Lexer\Console;

use Symfony\Component\Console\Application as BaseApplication;

/**
 * Symfony Console application for the Wik/Lexer CLI tool.
 *
 * Usage (via bin/lex):
 *   lex compile   <path> [--cache=<dir>] [--paths=<dir>...] [--production]
 *   lex validate  <path> [--cache=<dir>] [--sandbox]
 *   lex cache:clear <cache-dir>
 *   lex benchmark <template> [--paths=<dir>] [--cache=<dir>] [--iterations=<n>]
 */
final class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('Wik/Lexer', '1.0.0');

        $this->addCommands([
            new InitCommand(),
            new CompileCommand(),
            new CacheClearCommand(),
            new BenchmarkCommand(),
            new ValidateCommand(),
        ]);
    }
}
