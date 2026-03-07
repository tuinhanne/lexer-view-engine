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
use Wik\Lexer\Config\LexConfig;
use Wik\Lexer\Lexer;

/**
 * Compile all .lex template files in a directory (or a single file).
 *
 * Usage (explicit options):
 *   lex compile views/ --cache=storage/cache --paths=views/
 *   lex compile views/home.lex --cache=storage/cache --production
 *
 * Usage with lex.config.json in project root (options become optional):
 *   lex compile
 */
#[AsCommand(name: 'compile', description: 'Compile .lex template files to PHP')]
final class CompileCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Directory or file to compile (default: viewPaths from config)')
            ->addOption('cache', 'c', InputOption::VALUE_REQUIRED, 'Cache directory for compiled PHP files')
            ->addOption('paths', 'p', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'View lookup directories')
            ->addOption('production', null, InputOption::VALUE_NONE, 'Enable production mode (builds the precompiled index)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ── Resolve config (lex.config.json wins, CLI options override) ───────
        $config = LexConfig::tryLoad();

        $pathArg    = $input->getArgument('path');
        $cacheDir   = $input->getOption('cache')   ?? $config?->cache       ?? sys_get_temp_dir() . '/lex_cache';
        $viewPaths  = (array) $input->getOption('paths') ?: ($config?->viewPaths ?? []);
        $production = $input->getOption('production') || ($config?->production ?? false);

        if ($config && !$pathArg) {
            $io->note('Using settings from ' . $config->configFilePath);
        }

        // If no path argument, compile everything in the configured view paths
        if (!$pathArg) {
            if (empty($viewPaths)) {
                $io->error('No path specified and no viewPaths found in lex.config.json.');

                return Command::FAILURE;
            }

            $files = [];
            foreach ($viewPaths as $vp) {
                $files = array_merge($files, $this->collectFiles($vp));
            }
        } else {
            $files = $this->collectFiles($pathArg);
        }

        if (empty($files)) {
            $io->warning('No .' . LexConfig::DEFAULT_EXTENSION . ' files found.');

            return Command::SUCCESS;
        }

        // Build the engine
        $paths = !empty($viewPaths) ? $viewPaths : ($pathArg ? [is_dir($pathArg) ? $pathArg : dirname($pathArg)] : []);

        $lexer = (new Lexer())
            ->paths($paths)
            ->cache((string) $cacheDir);

        if ($production) {
            $lexer->setProduction();
        }

        $compiler  = $lexer->getCompiler();
        $compiled  = 0;
        $failed    = 0;

        $io->title('Wik/Lexer — Compile');
        $io->progressStart(count($files));

        foreach ($files as $file) {
            try {
                $source = file_get_contents($file);
                $compiler->compile($source, $file);
                $compiled++;
            } catch (\Throwable $e) {
                $io->progressAdvance();
                $io->newLine();
                $io->error("Failed: {$file}\n" . $e->getMessage());
                $failed++;

                continue;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success("Compiled {$compiled} template(s)" . ($failed > 0 ? ", {$failed} failed" : '') . '.');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return string[] */
    private function collectFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === LexConfig::DEFAULT_EXTENSION) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
