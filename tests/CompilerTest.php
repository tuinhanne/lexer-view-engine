<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Cache\FileCache;
use Wik\Lexer\Compiler\Compiler;
use Wik\Lexer\Support\DirectiveRegistry;

final class CompilerTest extends TestCase
{
    private string $cacheDir;
    private DirectiveRegistry $registry;
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/lexer_test_cache_' . uniqid();
        $this->registry = new DirectiveRegistry();
        $this->compiler = new Compiler($this->registry, new FileCache($this->cacheDir));
    }

    protected function tearDown(): void
    {
        // Clean up the .lexer/ base dir and its compiled/ and ast/ subdirectories
        $this->removeDir($this->cacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
        }

        rmdir($dir);
    }

    // -----------------------------------------------------------------------
    // Basic compilation
    // -----------------------------------------------------------------------

    public function testCompileReturnsExistingFile(): void
    {
        $source = '<p>Hello</p>';
        $path1  = $this->compiler->compile($source, 'test.lex');
        $path2  = $this->compiler->compile($source, 'test.lex');

        $this->assertSame($path1, $path2);
        $this->assertFileExists($path1);
    }

    public function testCompiledFileContainsSource(): void
    {
        $source = '<p>Hello World</p>';
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('Hello World', $content);
    }

    public function testEchoCompilesWithEnvEscape(): void
    {
        $source = '{{ $name }}';
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('$__env->escape(', $content);
        $this->assertStringContainsString('$name', $content);
    }

    public function testRawEchoCompilesWithoutEscaping(): void
    {
        $source = '{!! $html !!}';
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('echo $html', $content);
        $this->assertStringNotContainsString('htmlspecialchars', $content);
    }

    public function testIfBlockCompilesCorrectly(): void
    {
        $source = "#if (\$x > 0):\n<p>positive</p>\n#endif";
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('if ($x > 0)', $content);
        $this->assertStringContainsString('endif', $content);
    }

    public function testForeachBlockCompilesCorrectly(): void
    {
        $source = "#foreach (\$items as \$item):\n{{ \$item }}\n#endforeach";
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('foreach ($items as $item)', $content);
        $this->assertStringContainsString('endforeach', $content);
    }

    public function testSectionCompilesWithEnvCalls(): void
    {
        $source = "#section('content')\n<p>body</p>\n#endsection";
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('startSection', $content);
        $this->assertStringContainsString('endSection', $content);
    }

    public function testExtendsCompilesWithEnvCall(): void
    {
        $source = "#extends('layouts.main')";
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('extend(', $content);
        $this->assertStringContainsString('layouts.main', $content);
    }

    public function testYieldCompilesWithEnvCall(): void
    {
        $source = "#yield('content')";
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('yieldSection', $content);
        $this->assertStringContainsString('content', $content);
    }

    // -----------------------------------------------------------------------
    // Component compilation
    // -----------------------------------------------------------------------

    public function testSelfClosingComponentCompilesWithRenderComponent(): void
    {
        $source = '<Card title="Hello" />';
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('renderComponent', $content);
        $this->assertStringContainsString("'Card'", $content);
        $this->assertStringContainsString("'title'", $content);
        $this->assertStringContainsString("'Hello'", $content);
    }

    public function testComponentWithSlotCompilesWithStartEnd(): void
    {
        $source = "<Card title=\"Hi\">\n<p>slot</p>\n</Card>";
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('startComponent', $content);
        $this->assertStringContainsString('endComponent', $content);
    }

    public function testComponentWithExpressionPropCompilesWithoutQuotes(): void
    {
        $source = '<Card active={$isActive} />';
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('$isActive', $content);
    }

    // -----------------------------------------------------------------------
    // Custom directives
    // -----------------------------------------------------------------------

    public function testCustomDirectiveCompiles(): void
    {
        $this->registry->register(
            'upper',
            fn($expr) => "<?php echo strtoupper((string)({$expr})); ?>"
        );

        $source = '#upper($name)';
        $path   = $this->compiler->compile($source, 'test.lex');

        $content = file_get_contents($path);
        $this->assertStringContainsString('strtoupper', $content);
    }

    // -----------------------------------------------------------------------
    // Cache behaviour
    // -----------------------------------------------------------------------

    public function testCacheDirectoryIsCreated(): void
    {
        // The directory is created lazily on first compile, not at construction time.
        $this->compiler->compile('<p>hi</p>', 'test.lex');
        $this->assertDirectoryExists($this->cacheDir);
    }

    public function testAstFileIsCreated(): void
    {
        $source = '<p>Test</p>';
        $hash   = md5($source);
        $this->compiler->compile($source, 'test.lex');

        $this->assertFileExists($this->cacheDir . '/' . $hash . '.ast');
    }

    public function testRecompileInvalidatesCache(): void
    {
        $source = '<p>Original</p>';
        $path1  = $this->compiler->compile($source, 'test.lex');

        // Modify the cached file to simulate stale content
        file_put_contents($path1, '<?php // stale ?>');

        $path2 = $this->compiler->recompile($source, 'test.lex');

        $content = file_get_contents($path2);
        $this->assertStringContainsString('Original', $content);
    }

    // -----------------------------------------------------------------------
    // Direct parse / tokenize API
    // -----------------------------------------------------------------------

    public function testParseReturnsNodeArray(): void
    {
        $nodes = $this->compiler->parse('<p>{{ $x }}</p>');

        $this->assertIsArray($nodes);
        $this->assertNotEmpty($nodes);
    }

    public function testTokenizeReturnsTokenArray(): void
    {
        $tokens = $this->compiler->tokenize('{{ $x }}');

        $this->assertIsArray($tokens);
        $this->assertNotEmpty($tokens);
    }
}
