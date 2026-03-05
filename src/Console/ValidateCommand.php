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
use Wik\Lexer\Compiler\AstValidator;
use Wik\Lexer\Compiler\Compiler;
use Wik\Lexer\Compiler\OptimizePass;
use Wik\Lexer\Config\LexConfig;
use Wik\Lexer\Exceptions\TemplateSyntaxException;
use Wik\Lexer\Security\ExpressionValidator;
use Wik\Lexer\Security\SandboxConfig;
use Wik\Lexer\Support\DirectiveRegistry;

/**
 * Validate .lex template files for structural and sandbox compliance.
 *
 * Usage with explicit options:
 *   lex validate views/
 *   lex validate views/ --sandbox
 *   lex validate views/home.lex --cache=tmp
 *
 * Usage with lex.config.json in project root:
 *   lex validate
 */
#[AsCommand(name: 'validate', description: 'Validate .lex template files')]
final class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Directory or file to validate (default: viewPaths from config)')
            ->addOption('cache', 'c', InputOption::VALUE_REQUIRED, 'Cache directory (used for parsing only)')
            ->addOption('sandbox', null, InputOption::VALUE_NONE, 'Validate against secure sandbox rules')
            ->addOption('ext', null, InputOption::VALUE_REQUIRED, 'Template file extension');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config   = LexConfig::tryLoad();
        $pathArg  = $input->getArgument('path');
        $cacheDir = $input->getOption('cache') ?? $config?->cache ?? sys_get_temp_dir() . '/lex_validate_cache';
        $sandbox  = $input->getOption('sandbox') || ($config?->sandbox ?? false);
        $ext      = $input->getOption('ext') ?? $config?->extension ?? LexConfig::DEFAULT_EXTENSION;

        if ($config && !$pathArg) {
            $io->note('Using settings from ' . $config->configFilePath);
        }

        if (!$pathArg) {
            if (empty($config?->viewPaths)) {
                $io->error('No path specified and no viewPaths found in lex.config.json.');

                return Command::FAILURE;
            }

            $files = [];
            foreach ($config->viewPaths as $vp) {
                $files = array_merge($files, $this->collectFiles($vp, (string) $ext));
            }
        } else {
            $files = $this->collectFiles((string) $pathArg, (string) $ext);
        }

        if (empty($files)) {
            $io->warning("No .{$ext} files found.");

            return Command::SUCCESS;
        }

        $sandboxConfig = $sandbox ? SandboxConfig::secure() : null;

        $compiler = new Compiler(
            new DirectiveRegistry(),
            new FileCache((string) $cacheDir),
            false,
            $sandboxConfig,
        );

        $io->title('Wik/Lexer — Validate');

        $errors = [];

        foreach ($files as $file) {
            try {
                $source = file_get_contents($file);
                $nodes  = $compiler->parse($source);

                if ($sandboxConfig !== null) {
                    $validator = new AstValidator(
                        $sandboxConfig,
                        new ExpressionValidator($sandboxConfig),
                        $file,
                    );
                    $validator->validate($nodes);
                }

                $io->writeln(" <fg=green>✔</> {$file}");
            } catch (TemplateSyntaxException $e) {
                $errors[] = ['file' => $file, 'error' => $e->getMessage()];
                $io->writeln(" <fg=red>✖</> {$file}");
            } catch (\Throwable $e) {
                $errors[] = ['file' => $file, 'error' => $e->getMessage()];
                $io->writeln(" <fg=red>✖</> {$file}");
            }
        }

        if (!empty($errors)) {
            $io->newLine();
            $io->section('Errors');

            foreach ($errors as $err) {
                $io->error("{$err['file']}\n{$err['error']}");
            }

            $io->error(count($errors) . ' file(s) failed validation.');

            return Command::FAILURE;
        }

        $io->success('All ' . count($files) . ' template(s) passed validation.');

        return Command::SUCCESS;
    }

    /** @return string[] */
    private function collectFiles(string $path, string $ext): array
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
            if ($file->isFile() && $file->getExtension() === $ext) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
