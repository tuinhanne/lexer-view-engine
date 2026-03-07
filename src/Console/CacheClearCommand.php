<?php

declare(strict_types=1);

namespace Wik\Lexer\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wik\Lexer\Cache\DependencyGraph;
use Wik\Lexer\Cache\FileCache;
use Wik\Lexer\Config\LexConfig;

/**
 * Clear all compiled PHP files and AST caches from the .lexer/ directory.
 *
 * The cache root is always {projectRoot}/.lexer/ where the project root is
 * the directory containing lex.config.json (walked up from cwd).
 * Falls back to {cwd}/.lexer when no config file is found.
 *
 * Usage:
 *   lex cache:clear
 *   lex cache:clear --index-only
 */
#[AsCommand(name: 'cache:clear', description: 'Clear the compiled template cache')]
final class CacheClearCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('index-only', null, InputOption::VALUE_NONE, 'Only clear the precompiled index, not the compiled PHP files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Derive the .lexer/ root from the project root (lex.config.json location)
        // or fall back to the current working directory.
        $config   = LexConfig::tryLoad();
        $root     = $config?->projectRoot ?? (string) getcwd();
        $lexerDir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . LexConfig::CACHE_DIR;

        if (!is_dir($lexerDir)) {
            $io->warning("Cache directory does not exist: {$lexerDir}");

            return Command::SUCCESS;
        }

        $cache     = new FileCache($lexerDir);
        $indexOnly = (bool) $input->getOption('index-only');

        if ($indexOnly) {
            $cache->flushIndex();
            $io->success('Precompiled view index cleared.');
        } else {
            $cache->flush();
            (new DependencyGraph($lexerDir))->flush();
            $io->success("Cache cleared: {$lexerDir}");
        }

        return Command::SUCCESS;
    }
}
