<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Exceptions\TemplateSyntaxException;
use Wik\Lexer\Lexer;
use Wik\Lexer\Security\SandboxConfig;

/**
 * Tests for sandbox mode and expression validation.
 *
 * Covers:
 *   - Secure sandbox defaults (raw echo forbidden, function whitelist)
 *   - Always-blocked dangerous functions (eval, exec, system, shell_exec, …)
 *   - Function whitelist enforcement
 *   - Backtick operator blocking
 *   - Object instantiation blocking in strict mode
 *   - Custom sandbox configuration
 *   - Permissive (default) mode allows everything
 */
final class SandboxTest extends TestCase
{
    private string $viewDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $base           = sys_get_temp_dir() . '/lex_sandbox_test_' . uniqid();
        $this->viewDir  = $base . '/views';
        $this->cacheDir = $base . '/cache';

        mkdir($this->viewDir, 0755, true);
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir(dirname($this->viewDir));
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->deleteDir($item) : unlink($item);
        }
        rmdir($dir);
    }

    private function buildLexer(?SandboxConfig $config = null): Lexer
    {
        $lexer = (new Lexer())
            ->paths([$this->viewDir])
            ->cache($this->cacheDir);

        if ($config !== null) {
            $lexer->enableSandbox($config);
        } else {
            $lexer->enableSandbox();
        }

        return $lexer;
    }

    private function writeView(string $name, string $content): void
    {
        file_put_contents($this->viewDir . '/' . $name . '.lex', $content);
    }

    // -----------------------------------------------------------------------
    // Raw echo
    // -----------------------------------------------------------------------

    public function testRawEchoForbiddenInSecureSandbox(): void
    {
        $this->writeView('raw', '{!! $html !!}');

        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessageMatches('/raw echo/i');

        $this->buildLexer()->render('raw', ['html' => 'x']);
    }

    public function testRawEchoAllowedWhenPermitted(): void
    {
        $this->writeView('raw', '{!! $html !!}');

        $config = SandboxConfig::secure()->withRawEcho(true);
        $html   = $this->buildLexer($config)->render('raw', ['html' => '<b>bold</b>']);

        $this->assertStringContainsString('<b>bold</b>', $html);
    }

    // -----------------------------------------------------------------------
    // Always-blocked functions
    // -----------------------------------------------------------------------

    public function testEvalIsAlwaysBlocked(): void
    {
        $this->writeView('evil', '{{ eval("echo 1;") }}');

        $this->expectException(TemplateSyntaxException::class);

        $this->buildLexer()->render('evil');
    }

    public function testExecIsAlwaysBlocked(): void
    {
        $this->writeView('cmd', '{{ exec("ls") }}');

        $this->expectException(TemplateSyntaxException::class);

        $this->buildLexer()->render('cmd');
    }

    public function testSystemIsAlwaysBlocked(): void
    {
        $this->writeView('cmd', '{{ system("pwd") }}');

        $this->expectException(TemplateSyntaxException::class);

        $this->buildLexer()->render('cmd');
    }

    public function testShellExecIsAlwaysBlocked(): void
    {
        $this->writeView('cmd', '{{ shell_exec("id") }}');

        $this->expectException(TemplateSyntaxException::class);

        $this->buildLexer()->render('cmd');
    }

    public function testFileGetContentsIsAlwaysBlocked(): void
    {
        $this->writeView('file', '{{ file_get_contents("/etc/passwd") }}');

        $this->expectException(TemplateSyntaxException::class);

        $this->buildLexer()->render('file');
    }

    public function testBacktickIsAlwaysBlocked(): void
    {
        // Backtick execution in an expression
        $this->writeView('backtick', '{{ `ls` }}');

        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessageMatches('/backtick/i');

        $this->buildLexer()->render('backtick');
    }

    // -----------------------------------------------------------------------
    // Function whitelist
    // -----------------------------------------------------------------------

    public function testWhitelistedFunctionIsAllowed(): void
    {
        $this->writeView('safe', '{{ strtoupper($name) }}');

        $config = SandboxConfig::secure()->withAllowedFunctions(['strtoupper']);
        $html   = $this->buildLexer($config)->render('safe', ['name' => 'alice']);

        $this->assertStringContainsString('ALICE', $html);
    }

    public function testNonWhitelistedFunctionIsBlocked(): void
    {
        $this->writeView('blocked', '{{ date("Y") }}');

        $config = SandboxConfig::secure()->withAllowedFunctions(['strtoupper']);

        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessageMatches("/date.*not in the allowed/i");

        $this->buildLexer($config)->render('blocked');
    }

    public function testEmptyWhitelistBlocksAllFunctions(): void
    {
        $this->writeView('nofn', '{{ strlen($s) }}');

        $config = SandboxConfig::secure(); // allowedFunctions = []

        $this->expectException(TemplateSyntaxException::class);

        $this->buildLexer($config)->render('nofn', ['s' => 'hello']);
    }

    public function testNullWhitelistAllowsAllFunctions(): void
    {
        $this->writeView('anyfn', '{{ strtolower($s) }}');

        // allowedFunctions = null means all functions are allowed (permissive)
        $config = SandboxConfig::permissive();
        $lexer  = (new Lexer())
            ->paths([$this->viewDir])
            ->cache($this->cacheDir)
            ->setSandboxConfig($config);

        $html = $lexer->render('anyfn', ['s' => 'HELLO']);
        $this->assertStringContainsString('hello', $html);
    }

    // -----------------------------------------------------------------------
    // Object instantiation
    // -----------------------------------------------------------------------

    public function testNewKeywordBlockedWithFunctionWhitelist(): void
    {
        $this->writeView('new', '{{ (new stdClass())->x }}');

        $config = SandboxConfig::secure()->withAllowedFunctions(['strlen']);

        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessageMatches('/object instantiation/i');

        $this->buildLexer($config)->render('new');
    }

    // -----------------------------------------------------------------------
    // Permissive mode (no sandbox)
    // -----------------------------------------------------------------------

    public function testPermissiveModeAllowsRawEcho(): void
    {
        $this->writeView('raw', '{!! $html !!}');

        // No sandbox enabled — permissive by default
        $lexer = (new Lexer())
            ->paths([$this->viewDir])
            ->cache($this->cacheDir);

        $html = $lexer->render('raw', ['html' => '<em>ok</em>']);
        $this->assertStringContainsString('<em>ok</em>', $html);
    }

    public function testPermissiveModeAllowsAnyFunction(): void
    {
        $this->writeView('fn', '{{ date("Y") }}');

        $lexer = (new Lexer())
            ->paths([$this->viewDir])
            ->cache($this->cacheDir);

        $html = $lexer->render('fn');
        $this->assertStringContainsString((string) date('Y'), $html);
    }

    // -----------------------------------------------------------------------
    // SandboxConfig API
    // -----------------------------------------------------------------------

    public function testSandboxConfigSecureDefaults(): void
    {
        $config = SandboxConfig::secure();

        $this->assertFalse($config->allowRawEcho);
        $this->assertSame([], $config->allowedFunctions);
        $this->assertFalse($config->allowCustomDirectives);
    }

    public function testSandboxConfigPermissiveDefaults(): void
    {
        $config = SandboxConfig::permissive();

        $this->assertTrue($config->allowRawEcho);
        $this->assertNull($config->allowedFunctions);
        $this->assertTrue($config->allowCustomDirectives);
    }

    public function testSandboxConfigImmutableFluent(): void
    {
        $base    = SandboxConfig::secure();
        $updated = $base->withAllowedFunctions(['date', 'number_format']);

        $this->assertNotSame($base, $updated);
        $this->assertSame([], $base->allowedFunctions);
        $this->assertSame(['date', 'number_format'], $updated->allowedFunctions);
    }
}
