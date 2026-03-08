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
 *   lex validate views/home.lex
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
            ->addOption('sandbox', null, InputOption::VALUE_NONE, 'Validate against secure sandbox rules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config   = LexConfig::tryLoad();
        $pathArg  = $input->getArgument('path');
        $sandbox  = $input->getOption('sandbox') || ($config?->sandbox ?? false);

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
                $files = array_merge($files, $this->collectFiles($vp));
            }
        } else {
            $files = $this->collectFiles((string) $pathArg);
        }

        if (empty($files)) {
            $io->warning('No .' . LexConfig::DEFAULT_EXTENSION . ' files found.');

            return Command::SUCCESS;
        }

        $sandboxConfig = $sandbox ? SandboxConfig::secure() : null;

        // Derive .lexer/ root for the FileCache (parse() does not write to it,
        // but FileCache is required by Compiler's constructor).
        $root     = $config?->projectRoot ?? (string) getcwd();
        $lexerDir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . LexConfig::CACHE_DIR;

        $compiler = new Compiler(
            new DirectiveRegistry(),
            new FileCache($lexerDir),
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
