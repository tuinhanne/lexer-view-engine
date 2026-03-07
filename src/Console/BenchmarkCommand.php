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
 * Benchmark cold-compile and warm-render performance for a template.
 *
 * Measures three phases:
 *   1. Cold compile — lex + parse + optimize + PHP codegen (no cache)
 *   2. Warm render  — include pre-compiled PHP + execute
 *   3. Full render  — end-to-end Lexer::render() with warm cache
 *
 * Usage:
 *   lex benchmark home --paths=views --cache=tmp --iterations=100
 *   lex benchmark emails.welcome --paths=views --cache=tmp --data='{"name":"Alice"}'
 */
#[AsCommand(name: 'benchmark', description: 'Benchmark compile and render performance')]
final class BenchmarkCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('template', InputArgument::REQUIRED, 'Template name to benchmark (dot notation)')
            ->addOption('paths', 'p', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'View lookup directories')
            ->addOption('cache', 'c', InputOption::VALUE_REQUIRED, 'Cache directory', sys_get_temp_dir() . '/lex_bench_cache')
            ->addOption('iterations', 'i', InputOption::VALUE_REQUIRED, 'Number of render iterations', '100')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'JSON-encoded template variables', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $template   = (string) $input->getArgument('template');
        $paths      = (array) $input->getOption('paths');
        $cacheDir   = (string) ($input->getOption('cache') ?? sys_get_temp_dir() . '/lex_bench_cache');
        $iterations = (int) ($input->getOption('iterations') ?? 100);
        $dataJson   = (string) ($input->getOption('data') ?? '{}');

        $data = json_decode($dataJson, true) ?? [];

        // Fall back to lex.config.json when --paths is not specified
        if (empty($paths)) {
            $config = LexConfig::tryLoad();
            if ($config !== null) {
                $paths    = $config->viewPaths;
                $cacheDir = $input->getOption('cache') ?? $config->cache;
                $io->note('Using settings from ' . $config->configFilePath);
            }
        }

        if (empty($paths)) {
            $io->error('At least one --paths directory is required (or add a lex.config.json).');

            return Command::FAILURE;
        }

        $lexer = (new Lexer())
            ->paths($paths)
            ->cache((string) $cacheDir);

        $io->title('Wik/Lexer — Benchmark');
        $io->text("Template  : {$template}");
        $io->text("Iterations: {$iterations}");
        $io->newLine();

        // --- Phase 1: Cold compile ---
        $compiler   = $lexer->getCompiler();
        $engine     = $lexer->getEngine();
        $filePath   = $engine->resolveName($template);
        $source     = file_get_contents($filePath);

        // Clear existing cache to force cold compile
        $compiler->recompile($source, $filePath);

        $t0           = hrtime(true);
        $compiledPath = $compiler->compile($source, $filePath);
        $coldCompile  = (hrtime(true) - $t0) / 1_000_000; // ms

        $io->text(sprintf('Cold compile : <comment>%.3f ms</comment>', $coldCompile));

        // --- Phase 2: Warm render (include pre-compiled PHP) ---
        // Warm cache is already present from phase 1.
        $t0 = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $lexer->render($template, $data);
        }
        $warmTotal  = (hrtime(true) - $t0) / 1_000_000;
        $warmPerOp  = $warmTotal / $iterations;

        $io->text(sprintf(
            'Warm render : <comment>%.3f ms</comment> total, <comment>%.3f ms</comment> per iteration (%d×)',
            $warmTotal,
            $warmPerOp,
            $iterations,
        ));

        // --- Phase 3: Throughput ---
        $throughput = $iterations / ($warmTotal / 1000); // renders/second
        $io->text(sprintf('Throughput  : <comment>%.0f</comment> renders/sec', $throughput));

        $io->newLine();
        $io->success('Benchmark complete.');

        return Command::SUCCESS;
    }
}
