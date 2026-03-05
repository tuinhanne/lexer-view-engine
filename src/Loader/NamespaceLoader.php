<?php

declare(strict_types=1);

namespace Wik\Lexer\Loader;

use Wik\Lexer\Config\LexConfig;
use Wik\Lexer\Contracts\LoaderInterface;
use Wik\Lexer\Exceptions\ViewException;

/**
 * Namespace-aware template loader.
 *
 * Wraps a base FileLoader and adds namespace support.  A namespace is a
 * short alias that maps to a directory:
 *
 *   $loader->addNamespace('admin', '/path/to/admin/views');
 *
 *   // Template 'admin::dashboard' resolves to '/path/to/admin/views/dashboard.lex'
 *   // Template 'dashboard'       falls through to the wrapped base loader
 *
 * The namespace separator is '::' by default and may not be changed.
 */
final class NamespaceLoader implements LoaderInterface
{
    /** @var array<string, FileLoader> namespace => loader */
    private array $namespaces = [];

    public function __construct(
        private readonly FileLoader $base,
    ) {
    }

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /**
     * Register a namespace pointing to the given directory.
     *
     * @param string   $namespace  Short name (e.g. 'admin')
     * @param string   $directory  Absolute directory path
     * @param string   $extension  File extension for this namespace
     */
    public function addNamespace(string $namespace, string $directory, string $extension = LexConfig::DEFAULT_EXTENSION): void
    {
        if (!isset($this->namespaces[$namespace])) {
            $this->namespaces[$namespace] = new FileLoader([], $extension);
        }

        $this->namespaces[$namespace]->addDirectory($directory);
    }

    // -----------------------------------------------------------------------
    // LoaderInterface
    // -----------------------------------------------------------------------

    public function load(string $name): string
    {
        [$namespace, $view] = $this->splitName($name);

        if ($namespace !== null) {
            return $this->getNamespaceLoader($namespace)->load($view);
        }

        return $this->base->load($name);
    }

    public function exists(string $name): bool
    {
        [$namespace, $view] = $this->splitName($name);

        if ($namespace !== null) {
            $loader = $this->namespaces[$namespace] ?? null;

            return $loader !== null && $loader->exists($view);
        }

        return $this->base->exists($name);
    }

    public function getPath(string $name): ?string
    {
        [$namespace, $view] = $this->splitName($name);

        if ($namespace !== null) {
            $loader = $this->namespaces[$namespace] ?? null;

            return $loader?->getPath($view);
        }

        return $this->base->getPath($name);
    }

    public function getCacheKey(string $name): string
    {
        [$namespace, $view] = $this->splitName($name);

        if ($namespace !== null) {
            $loader = $this->namespaces[$namespace] ?? null;

            if ($loader !== null) {
                return md5('ns:' . $namespace . ':' . $loader->getCacheKey($view));
            }
        }

        return $this->base->getCacheKey($name);
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Split a name like 'admin::dashboard' into ['admin', 'dashboard'].
     * A plain name returns [null, $name].
     *
     * @return array{string|null, string}
     */
    private function splitName(string $name): array
    {
        if (str_contains($name, '::')) {
            [$ns, $view] = explode('::', $name, 2);

            return [trim($ns), trim($view)];
        }

        return [null, $name];
    }

    private function getNamespaceLoader(string $namespace): FileLoader
    {
        if (!isset($this->namespaces[$namespace])) {
            throw new ViewException(
                "No namespace '{$namespace}' has been registered. "
                . 'Register it with $loader->addNamespace().'
            );
        }

        return $this->namespaces[$namespace];
    }
}
