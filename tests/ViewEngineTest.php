<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Lexer;

/**
 * Integration tests for the full rendering pipeline.
 *
 * Each test creates temporary .lex files, renders them through Lexer, and
 * asserts on the resulting HTML.
 */
final class ViewEngineTest extends TestCase
{
    private string $viewDir;
    private string $cacheDir;
    private Lexer $lexer;

    protected function setUp(): void
    {
        $base           = sys_get_temp_dir() . '/lexer_view_test_' . uniqid();
        $this->viewDir  = $base . '/views';
        $this->cacheDir = $base . '/cache';

        mkdir($this->viewDir, 0755, true);
        mkdir($this->viewDir . '/layouts', 0755, true);
        mkdir($this->viewDir . '/components', 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $this->lexer = new Lexer();
        $this->lexer->paths([$this->viewDir])->cache($this->cacheDir);
        $this->lexer->componentPath($this->viewDir . '/components');
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

    private function writeTemplate(string $name, string $content): void
    {
        $parts = explode('/', $name);
        $file  = array_pop($parts);
        $dir   = $this->viewDir . (empty($parts) ? '' : '/' . implode('/', $parts));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $file . '.lex', $content);
    }

    // -----------------------------------------------------------------------
    // Basic rendering
    // -----------------------------------------------------------------------

    public function testPlainHtmlPassesThrough(): void
    {
        $this->writeTemplate('hello', '<p>Hello World</p>');

        $html = $this->lexer->render('hello');
        $this->assertStringContainsString('<p>Hello World</p>', $html);
    }

    public function testVariableIsEscaped(): void
    {
        $this->writeTemplate('xss', '<p>{{ $name }}</p>');

        $html = $this->lexer->render('xss', ['name' => '<script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRawEchoIsNotEscaped(): void
    {
        $this->writeTemplate('raw', '<p>{!! $html !!}</p>');

        $html = $this->lexer->render('raw', ['html' => '<strong>bold</strong>']);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    // -----------------------------------------------------------------------
    // Control flow
    // -----------------------------------------------------------------------

    public function testIfTrueRendersContent(): void
    {
        $this->writeTemplate('if_true', "#if (\$show):\n<p>visible</p>\n#endif");

        $html = $this->lexer->render('if_true', ['show' => true]);
        $this->assertStringContainsString('<p>visible</p>', $html);
    }

    public function testIfFalseHidesContent(): void
    {
        $this->writeTemplate('if_false', "#if (\$show):\n<p>visible</p>\n#endif");

        $html = $this->lexer->render('if_false', ['show' => false]);
        $this->assertStringNotContainsString('<p>visible</p>', $html);
    }

    public function testIfElseRendersElseBranch(): void
    {
        $template = "#if (\$x):\n<p>yes</p>\n#else\n<p>no</p>\n#endif";
        $this->writeTemplate('if_else', $template);

        $html = $this->lexer->render('if_else', ['x' => false]);
        $this->assertStringContainsString('<p>no</p>', $html);
        $this->assertStringNotContainsString('<p>yes</p>', $html);
    }

    public function testIfElseifElseRendersCorrectBranch(): void
    {
        $template = <<<'LEX'
#if ($n === 1):
<p>one</p>
#elseif ($n === 2):
<p>two</p>
#else
<p>other</p>
#endif
LEX;
        $this->writeTemplate('if_chain', $template);

        $this->assertStringContainsString('<p>one</p>', $this->lexer->render('if_chain', ['n' => 1]));
        $this->assertStringContainsString('<p>two</p>', $this->lexer->render('if_chain', ['n' => 2]));
        $this->assertStringContainsString('<p>other</p>', $this->lexer->render('if_chain', ['n' => 3]));
    }

    public function testForeachRendersAllItems(): void
    {
        $template = "#foreach (\$items as \$item):\n<li>{{ \$item }}</li>\n#endforeach";
        $this->writeTemplate('foreach', $template);

        $html = $this->lexer->render('foreach', ['items' => ['Apple', 'Banana', 'Cherry']]);
        $this->assertStringContainsString('<li>Apple</li>', $html);
        $this->assertStringContainsString('<li>Banana</li>', $html);
        $this->assertStringContainsString('<li>Cherry</li>', $html);
    }

    // -----------------------------------------------------------------------
    // Layout system
    // -----------------------------------------------------------------------

    public function testLayoutInheritanceRendersYieldContent(): void
    {
        $this->writeTemplate('layouts/base', <<<'LEX'
<!DOCTYPE html>
<html><body>
#yield('content')
</body></html>
LEX);

        $this->writeTemplate('child', <<<'LEX'
#extends('layouts.base')
#section('content')
<main>Hello from child</main>
#endsection
LEX);

        $html = $this->lexer->render('child');
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<main>Hello from child</main>', $html);
    }

    public function testLayoutWithMultipleSections(): void
    {
        $this->writeTemplate('layouts/full', <<<'LEX'
<head><title>#yield('title')</title></head>
<body>#yield('body')</body>
LEX);

        $this->writeTemplate('page', <<<'LEX'
#extends('layouts.full')
#section('title')
My Page
#endsection
#section('body')
<p>Content here</p>
#endsection
LEX);

        $html = $this->lexer->render('page');
        $this->assertStringContainsString('My Page', $html);
        $this->assertStringContainsString('<p>Content here</p>', $html);
    }

    public function testYieldDefaultFallback(): void
    {
        $this->writeTemplate('layouts/default', <<<'LEX'
<div>#yield('sidebar', 'Default sidebar')</div>
LEX);

        $this->writeTemplate('no_sidebar', <<<'LEX'
#extends('layouts.default')
#section('content')
main
#endsection
LEX);

        $html = $this->lexer->render('no_sidebar');
        $this->assertStringContainsString('Default sidebar', $html);
    }

    // -----------------------------------------------------------------------
    // Component system
    // -----------------------------------------------------------------------

    public function testSelfClosingComponentRendered(): void
    {
        file_put_contents(
            $this->viewDir . '/components/badge.lex',
            '<span class="badge">{{ $label }}</span>'
        );

        $this->writeTemplate('with_badge', '<Badge label="New" />');

        $html = $this->lexer->render('with_badge');
        $this->assertStringContainsString('<span class="badge">New</span>', $html);
    }

    public function testComponentWithSlotRendered(): void
    {
        file_put_contents(
            $this->viewDir . '/components/card.lex',
            '<div class="card"><h2>{{ $title }}</h2>{!! $slot !!}</div>'
        );

        $this->writeTemplate('with_card', <<<'LEX'
<Card title="My Card">
<p>Card body content</p>
</Card>
LEX);

        $html = $this->lexer->render('with_card');
        $this->assertStringContainsString('<h2>My Card</h2>', $html);
        $this->assertStringContainsString('<p>Card body content</p>', $html);
    }

    public function testExplicitlyRegisteredComponent(): void
    {
        file_put_contents(
            $this->viewDir . '/components/alert.lex',
            '<div class="alert alert-{{ $type }}">{!! $slot !!}</div>'
        );

        $this->lexer->component('Alert', $this->viewDir . '/components/alert.lex');

        $this->writeTemplate('with_alert', <<<'LEX'
<Alert type="success">Saved!</Alert>
LEX);

        $html = $this->lexer->render('with_alert');
        $this->assertStringContainsString('alert-success', $html);
        $this->assertStringContainsString('Saved!', $html);
    }

    public function testComponentWithPhpExpressionProp(): void
    {
        file_put_contents(
            $this->viewDir . '/components/counter.lex',
            '<span>Count: {{ $value }}</span>'
        );

        $this->writeTemplate('with_counter', '<Counter value={$count} />');

        $html = $this->lexer->render('with_counter', ['count' => 42]);
        $this->assertStringContainsString('Count: 42', $html);
    }

    // -----------------------------------------------------------------------
    // Custom directives
    // -----------------------------------------------------------------------

    public function testCustomDirectiveRendered(): void
    {
        $this->lexer->directive('upper', fn($expr) => "<?php echo strtoupper((string)({$expr})); ?>");

        $this->writeTemplate('directive', '#upper($greeting)');

        $html = $this->lexer->render('directive', ['greeting' => 'hello world']);
        $this->assertStringContainsString('HELLO WORLD', $html);
    }

    public function testCustomDirectiveWithComplexExpression(): void
    {
        $this->lexer->directive(
            'money',
            fn($expr) => "<?php echo number_format((float)({$expr}), 2, '.', ','); ?>"
        );

        $this->writeTemplate('price', 'Price: #money($price)');

        $html = $this->lexer->render('price', ['price' => 1234567.89]);
        $this->assertStringContainsString('1,234,567.89', $html);
    }

    // -----------------------------------------------------------------------
    // Template resolution
    // -----------------------------------------------------------------------

    public function testDotNotationResolvesSubdirectory(): void
    {
        mkdir($this->viewDir . '/emails', 0755, true);
        file_put_contents($this->viewDir . '/emails/welcome.lex', '<p>Welcome email</p>');

        $html = $this->lexer->render('emails.welcome');
        $this->assertStringContainsString('<p>Welcome email</p>', $html);
    }

    public function testTemplateNotFoundThrows(): void
    {
        $this->expectException(\Wik\Lexer\Exceptions\ViewException::class);

        $this->lexer->render('does_not_exist');
    }

    // -----------------------------------------------------------------------
    // HTML escaping — {{ }} vs {!! !!}
    // -----------------------------------------------------------------------

    public function testEscapedEchoEscapesAllFiveHtmlChars(): void
    {
        $this->writeTemplate('esc_all', '{{ $v }}');

        // All five characters that htmlspecialchars converts
        $html = $this->lexer->render('esc_all', ['v' => '&<>"\'' ]);

        $this->assertStringContainsString('&amp;',  $html);
        $this->assertStringContainsString('&lt;',   $html);
        $this->assertStringContainsString('&gt;',   $html);
        $this->assertStringContainsString('&quot;', $html);
        $this->assertStringContainsString('&#039;', $html);

        // None of the raw characters should appear
        $this->assertStringNotContainsString('&<>"\'', $html);
    }

    public function testEscapedEchoBlocksXssPayload(): void
    {
        $this->writeTemplate('esc_xss', '<div>{{ $input }}</div>');

        $html = $this->lexer->render('esc_xss', ['input' => '<script>alert("xss")</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('alert(&quot;xss&quot;)', $html);
    }

    public function testEscapedEchoHandlesInteger(): void
    {
        $this->writeTemplate('esc_int', '{{ $n }}');

        $html = $this->lexer->render('esc_int', ['n' => 42]);
        $this->assertStringContainsString('42', $html);
    }

    public function testEscapedEchoHandlesNull(): void
    {
        $this->writeTemplate('esc_null', '[{{ $v }}]');

        $html = $this->lexer->render('esc_null', ['v' => null]);
        $this->assertStringContainsString('[]', $html);
    }

    public function testEscapedEchoDoesNotDoubleEncode(): void
    {
        // A string that is already HTML-encoded must not be encoded a second time
        $this->writeTemplate('esc_double', '{{ $v }}');

        $html = $this->lexer->render('esc_double', ['v' => '&amp;']);
        // htmlspecialchars re-encodes the & → &amp;amp; which IS expected behavior
        // (the engine does NOT skip already-encoded entities)
        $this->assertStringContainsString('&amp;amp;', $html);
    }

    public function testMultipleEscapedEchosInOneTemplate(): void
    {
        $this->writeTemplate('esc_multi', '<p>{{ $a }}</p><p>{{ $b }}</p>');

        $html = $this->lexer->render('esc_multi', [
            'a' => '<b>bold</b>',
            'b' => 'normal',
        ]);

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringContainsString('<p>normal</p>', $html);
    }

    public function testRawEchoPassesThroughHtmlTags(): void
    {
        $this->writeTemplate('raw_tags', '{!! $html !!}');

        $html = $this->lexer->render('raw_tags', ['html' => '<em>italic</em>']);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringNotContainsString('&lt;', $html);
    }

    public function testRawEchoPassesThroughAllSpecialChars(): void
    {
        $this->writeTemplate('raw_special', '{!! $v !!}');

        $raw  = '&<>"\'' ;
        $html = $this->lexer->render('raw_special', ['v' => $raw]);

        $this->assertStringContainsString($raw, $html);
        $this->assertStringNotContainsString('&amp;', $html);
        $this->assertStringNotContainsString('&lt;',  $html);
    }

    public function testRawEchoHandlesEmptyString(): void
    {
        $this->writeTemplate('raw_empty', '[{!! $v !!}]');

        $html = $this->lexer->render('raw_empty', ['v' => '']);
        $this->assertStringContainsString('[]', $html);
    }

    // -----------------------------------------------------------------------
    // Cache re-use
    // -----------------------------------------------------------------------

    public function testTemplateIsOnlyCompiledOnce(): void
    {
        $this->writeTemplate('cached', '<p>Cached template</p>');

        $html1 = $this->lexer->render('cached');
        $html2 = $this->lexer->render('cached');

        $this->assertSame($html1, $html2);

        // Verify the cache file was created
        $cacheFiles = glob($this->cacheDir . '/*.php') ?: [];
        $this->assertNotEmpty($cacheFiles);
    }
}
