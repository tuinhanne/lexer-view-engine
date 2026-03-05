<?php

declare(strict_types=1);

namespace Wik\Lexer\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wik\Lexer\Cache\FileCache;

/**
 * Clear all compiled PHP files and AST caches from the cache directory.
 *
 * Usage:
 *   lex cache:clear storage/cache
 *   lex cache:clear storage/cache --index-only
 */
#[AsCommand(name: 'cache:clear', description: 'Clear the compiled template cache')]
final class CacheClearCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('cache-dir', InputArgument::REQUIRED, 'Cache directory to clear')
            ->addOption('index-only', null, InputOption::VALUE_NONE, 'Only clear the precompiled index, not the compiled PHP files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $cacheDir = (string) $input->getArgument('cache-dir');

        if (!is_dir($cacheDir)) {
            $io->warning("Cache directory does not exist: {$cacheDir}");

            return Command::SUCCESS;
        }

        $cache     = new FileCache($cacheDir);
        $indexOnly = (bool) $input->getOption('index-only');

        if ($indexOnly) {
            $cache->flushIndex();
            $io->success('Precompiled view index cleared.');
        } else {
            $cache->flush();
            $io->success("Cache cleared: {$cacheDir}");
        }

        return Command::SUCCESS;
    }
}
