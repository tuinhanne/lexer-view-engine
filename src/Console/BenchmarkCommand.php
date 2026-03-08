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
 * Measures two phases:
 *   1. Compile      — cache-check + recompile if stale (or cold compile if no cache exists)
 *   2. Warm render  — include pre-compiled PHP + execute (N iterations)
 *
 * The cache is automatically placed in {projectRoot}/.lexer/ (derived
 * from lex.config.json or cwd when no config is present).
 *
 * Templates that use custom directives must be pre-compiled first
 * (run `lex compile` in a bootstrap that registers the directives).
 * Once pre-compiled the benchmark reads directly from the compiled cache.
 *
 * For true cold-compile measurements, clear the cache first:
 *   lex cache:clear && lex benchmark home
 *
 * Usage:
 *   lex benchmark home --paths=views --iterations=100
 *   lex benchmark emails.welcome --paths=views --data='{"name":"Alice"}'
 */
#[AsCommand(name: 'benchmark', description: 'Benchmark compile and render performance')]
final class BenchmarkCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('template', InputArgument::REQUIRED, 'Template name to benchmark (dot notation)')
            ->addOption('paths', 'p', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'View lookup directories')
            ->addOption('iterations', 'i', InputOption::VALUE_REQUIRED, 'Number of render iterations', '100')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'JSON-encoded template variables', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $template   = (string) $input->getArgument('template');
        $paths      = (array) $input->getOption('paths');
        $iterations = (int) ($input->getOption('iterations') ?? 100);
        $dataJson   = (string) ($input->getOption('data') ?? '{}');

        $data = json_decode($dataJson, true) ?? [];

        // Fall back to lex.config.json when --paths is not specified.
        // The project root (and thus .lexer/ cache location) is derived
        // from the config file automatically.
        $config = LexConfig::tryLoad();
        if (empty($paths)) {
            if ($config !== null) {
                $paths = $config->viewPaths;
                $io->note('Using settings from ' . $config->configFilePath);
            }
        }

        if (empty($paths)) {
            $io->error('At least one --paths directory is required (or add a lex.config.json).');

            return Command::FAILURE;
        }

        if ($config !== null) {
            $lexer = Lexer::fromConfig()->paths($paths);
        } else {
            $lexer = (new Lexer())->paths($paths);
        }

        $io->title('Wik/Lexer — Benchmark');
        $io->text("Template  : {$template}");
        $io->text("Iterations: {$iterations}");
        $io->newLine();

        $compiler = $lexer->getCompiler();
        $engine   = $lexer->getEngine();
        $filePath = $engine->resolveName($template);
        $source   = (string) file_get_contents($filePath);

        // --- Phase 1: Compile (cache-check + compile if not yet cached) ---
        // We do NOT force a recompile here because templates that use custom
        // directives (registered programmatically in application code) cannot
        // be re-parsed by the CLI.  If you need a true cold-compile measurement,
        // clear the cache first:  lex cache:clear && lex benchmark <template>
        try {
            $t0          = hrtime(true);
            $compiler->compile($source, $filePath);
            $compileTime = (hrtime(true) - $t0) / 1_000_000; // ms

            $io->text(sprintf('Compile      : <comment>%.3f ms</comment>', $compileTime));
        } catch (\Throwable $e) {
            $io->error([
                'Compilation failed: ' . $e->getMessage(),
                '',
                'Templates that use custom directives must be pre-compiled before benchmarking.',
                'Run "lex compile" in a bootstrap that registers all directives, then retry.',
            ]);

            return Command::FAILURE;
        }

        // --- Phase 2: Warm render ---
        try {
            $t0 = hrtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $lexer->render($template, $data);
            }
            $warmTotal = (hrtime(true) - $t0) / 1_000_000;
            $warmPerOp = $warmTotal / $iterations;

            $io->text(sprintf(
                'Warm render  : <comment>%.3f ms</comment> total, <comment>%.3f ms</comment> per iteration (%d×)',
                $warmTotal,
                $warmPerOp,
                $iterations,
            ));

            // --- Throughput ---
            $throughput = $iterations / ($warmTotal / 1000);
            $io->text(sprintf('Throughput   : <comment>%.0f</comment> renders/sec', $throughput));
        } catch (\Throwable $e) {
            $io->error('Render failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $io->success('Benchmark complete.');

        return Command::SUCCESS;
    }
}
