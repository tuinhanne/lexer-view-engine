<?php

declare(strict_types=1);

namespace Wik\Lexer\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wik\Lexer\Config\LexConfig;

/**
 * Create a lex.config.json in the current (or specified) directory.
 *
 * Usage:
 *   lex init
 *   lex init --dir=/path/to/project
 *   lex init --force          # overwrite existing file
 *   lex init --no-vscode      # skip .vscode/settings.json
 *
 * The command is interactive: it asks for view paths and cache dir, then
 * writes lex.config.json.  It also optionally writes (or merges)
 * .vscode/settings.json so the LSP extension picks up the same paths
 * without any extra configuration.
 */
#[AsCommand(name: 'init', description: 'Create a lex.config.json in your project root')]
final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dir',      'd', InputOption::VALUE_REQUIRED, 'Project root directory (default: current directory)')
            ->addOption('force',    'f', InputOption::VALUE_NONE,     'Overwrite existing lex.config.json')
            ->addOption('no-vscode', null, InputOption::VALUE_NONE,   'Do not create / update .vscode/settings.json')
            ->addOption('defaults', null, InputOption::VALUE_NONE,    'Use defaults without prompting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dir      = (string) ($input->getOption('dir') ?: getcwd());
        $dir      = rtrim(realpath($dir) ?: $dir, DIRECTORY_SEPARATOR);
        $force    = (bool) $input->getOption('force');
        $noVscode = (bool) $input->getOption('no-vscode');
        $defaults = (bool) $input->getOption('defaults');

        $configFile = $dir . DIRECTORY_SEPARATOR . LexConfig::FILE_NAME;

        // ── Guard: already exists ─────────────────────────────────────────────
        if (is_file($configFile) && !$force) {
            $io->warning([
                LexConfig::FILE_NAME . ' already exists.',
                'Run with --force to overwrite.',
            ]);

            return Command::SUCCESS;
        }

        $io->title('Wik/Lexer — Init');
        $io->text("Creating <comment>{$configFile}</comment>");
        $io->newLine();

        // ── Gather values ─────────────────────────────────────────────────────
        if ($defaults) {
            $viewPathsRaw = 'views,resources/views';
            $cache        = LexConfig::DEFAULT_CACHE_PATH;
        } else {
            $viewPathsRaw = $io->ask(
                'View paths (comma-separated, relative to project root)',
                'views,resources/views',
            ) ?? 'views,resources/views';

            $cache = $io->ask(
                'Cache directory',
                LexConfig::DEFAULT_CACHE_PATH,
            ) ?? LexConfig::DEFAULT_CACHE_PATH;
        }

        $viewPaths = array_values(array_filter(
            array_map('trim', explode(',', $viewPathsRaw)),
        ));

        // ── Write lex.config.json ─────────────────────────────────────────────
        $config = [
            'viewPaths'  => $viewPaths,
            'cache'      => $cache,
            'production' => false,
            'sandbox'    => false,
        ];

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if (file_put_contents($configFile, $json) === false) {
            $io->error("Could not write to {$configFile}");

            return Command::FAILURE;
        }

        $io->success(LexConfig::FILE_NAME . ' created.');

        // ── Write .vscode/settings.json (optional) ────────────────────────────
        if (!$noVscode) {
            $this->writeVscodeSettings($io, $dir, $viewPaths);
        }

        // ── Hint ──────────────────────────────────────────────────────────────
        $io->section('Next steps');
        $io->listing([
            'Review <comment>' . LexConfig::FILE_NAME . '</comment> and adjust paths if needed.',
            'Run <comment>lex compile</comment> to pre-compile all templates.',
            'The Lex LSP extension will automatically pick up <comment>' . LexConfig::FILE_NAME . '</comment>.',
        ]);

        return Command::SUCCESS;
    }

    // ── VS Code helper ────────────────────────────────────────────────────────

    /** @param string[] $viewPaths */
    private function writeVscodeSettings(
        SymfonyStyle $io,
        string $dir,
        array $viewPaths,
    ): void {
        $vscodeDir  = $dir . DIRECTORY_SEPARATOR . '.vscode';
        $settingsFile = $vscodeDir . DIRECTORY_SEPARATOR . 'settings.json';

        // Create .vscode/ if needed
        if (!is_dir($vscodeDir)) {
            if (!mkdir($vscodeDir, 0755, true)) {
                $io->warning('Could not create .vscode/ directory. Skipping VS Code settings.');

                return;
            }
        }

        // Load existing settings (if any) so we don't clobber them
        $existing = [];
        if (is_file($settingsFile)) {
            $raw = file_get_contents($settingsFile);
            if ($raw !== false) {
                try {
                    $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($parsed)) {
                        $existing = $parsed;
                    }
                } catch (\JsonException) {
                    // Malformed existing file – leave as-is, don't overwrite
                    $io->warning(".vscode/settings.json exists but contains invalid JSON. Skipping.");

                    return;
                }
            }
        }

        // Merge: only set if not already present, so we don't override user choices
        $existing['lexTemplate.viewPaths'] ??= $viewPaths;

        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if (file_put_contents($settingsFile, $json) !== false) {
            $io->note('.vscode/settings.json updated with lexTemplate.viewPaths.');
        } else {
            $io->warning('Could not write .vscode/settings.json.');
        }
    }
}
