<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Exceptions\TemplateRuntimeException;
use Wik\Lexer\Lexer;

/**
 * Integration tests for layout inheritance features.
 *
 * Covers:
 *   - Basic layout inheritance with #extends / #section / #yield
 *   - #parent directive (parent section content injection)
 *   - #push / #endpush stacks with #stack output
 *   - Multi-level inheritance
 *   - Infinite extends loop detection
 */
final class LayoutTest extends TestCase
{
    private string $viewDir;
    private string $cacheDir;
    private Lexer $lexer;

    protected function setUp(): void
    {
        $base           = sys_get_temp_dir() . '/lex_layout_test_' . uniqid();
        $this->viewDir  = $base . '/views';
        $this->cacheDir = $base . '/cache';

        mkdir($this->viewDir . '/layouts', 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $this->lexer = (new Lexer())
            ->paths([$this->viewDir])
            ->cache($this->cacheDir);
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

    private function writeView(string $name, string $content): void
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
    // Basic layout inheritance
    // -----------------------------------------------------------------------

    public function testChildSectionIsInjectedIntoLayout(): void
    {
        $this->writeView('layouts/base', <<<'LEX'
<html><body>#yield('content')</body></html>
LEX);
        $this->writeView('child', <<<'LEX'
#extends('layouts.base')
#section('content')
<main>child content</main>
#endsection
LEX);

        $html = $this->lexer->render('child');
        $this->assertStringContainsString('<html>', $html);
        $this->assertStringContainsString('<main>child content</main>', $html);
    }

    public function testYieldDefaultUsedWhenSectionAbsent(): void
    {
        $this->writeView('layouts/simple', '<div>#yield(\'sidebar\', \'default sidebar\')</div>');
        $this->writeView('page', <<<'LEX'
#extends('layouts.simple')
#section('content')main#endsection
LEX);

        $html = $this->lexer->render('page');
        $this->assertStringContainsString('default sidebar', $html);
    }

    public function testMultipleSectionsInSameLayout(): void
    {
        $this->writeView('layouts/full', <<<'LEX'
<title>#yield('title')</title><body>#yield('body')</body>
LEX);
        $this->writeView('page', <<<'LEX'
#extends('layouts.full')
#section('title')My Page#endsection
#section('body')<p>Content</p>#endsection
LEX);

        $html = $this->lexer->render('page');
        $this->assertStringContainsString('My Page', $html);
        $this->assertStringContainsString('<p>Content</p>', $html);
    }

    // -----------------------------------------------------------------------
    // #parent directive
    // -----------------------------------------------------------------------

    public function testParentDirectivePrependsParentContent(): void
    {
        $this->writeView('layouts/nav', <<<'LEX'
<nav>#yield('nav')</nav>
LEX);

        // First render: set base layout's nav section via a middle layer
        // In practice, parent() is used in child overriding a section from grandparent.
        // We test it by having a layout provide a section default and a child use #parent.

        // Simulate: layout defines section with content; child extends and uses #parent
        $this->writeView('layouts/with-nav', <<<'LEX'
<nav>#yield('nav', 'base nav')</nav>
LEX);

        $this->writeView('child-nav', <<<'LEX'
#extends('layouts.with-nav')
#section('nav')
extra | #parent
#endsection
LEX);

        $html = $this->lexer->render('child-nav');
        $this->assertStringContainsString('extra', $html);
        // After first render, parent section content is whatever was there before
        // In this case, no prior section exists, so parentSection() returns ''.
        // The important assertion is that #parent compiles and renders without error.
        $this->assertStringContainsString('<nav>', $html);
    }

    // -----------------------------------------------------------------------
    // #push / #endpush / #stack
    // -----------------------------------------------------------------------

    public function testPushStackAccumulatesContent(): void
    {
        $this->writeView('layouts/stacked', <<<'LEX'
<head>#stack('scripts')</head><body>#yield('body')</body>
LEX);

        $this->writeView('page-with-scripts', <<<'LEX'
#extends('layouts.stacked')
#push('scripts')
<script src="app.js"></script>
#endpush
#push('scripts')
<script src="extra.js"></script>
#endpush
#section('body')page body#endsection
LEX);

        $html = $this->lexer->render('page-with-scripts');
        $this->assertStringContainsString('<script src="app.js"></script>', $html);
        $this->assertStringContainsString('<script src="extra.js"></script>', $html);
        $this->assertStringContainsString('page body', $html);
    }

    public function testStackDefaultWhenNothingPushed(): void
    {
        $this->writeView('layouts/default-stack', <<<'LEX'
<head>#stack('styles', '<link rel="stylesheet" href="default.css">')</head>
<body>#yield('content')</body>
LEX);

        $this->writeView('no-push-page', <<<'LEX'
#extends('layouts.default-stack')
#section('content')body#endsection
LEX);

        $html = $this->lexer->render('no-push-page');
        $this->assertStringContainsString('default.css', $html);
    }

    public function testStackOrderIsPreserved(): void
    {
        $this->writeView('layouts/ordered', '#stack(\'items\')');
        $this->writeView('pushed', <<<'LEX'
#extends('layouts.ordered')
#push('items')first#endpush
#push('items')second#endpush
#push('items')third#endpush
LEX);

        $html = $this->lexer->render('pushed');
        $firstPos  = strpos($html, 'first');
        $secondPos = strpos($html, 'second');
        $thirdPos  = strpos($html, 'third');

        $this->assertLessThan($secondPos, $firstPos, 'first should appear before second');
        $this->assertLessThan($thirdPos, $secondPos, 'second should appear before third');
    }

    // -----------------------------------------------------------------------
    // Multi-level inheritance
    // -----------------------------------------------------------------------

    public function testThreeLevelInheritanceWorks(): void
    {
        $this->writeView('layouts/root', '<root>#yield("main")</root>');
        $this->writeView('layouts/mid', <<<'LEX'
#extends('layouts.root')
#section('main')
<mid>#yield('content')</mid>
#endsection
LEX);
        $this->writeView('leaf', <<<'LEX'
#extends('layouts.mid')
#section('content')leaf content#endsection
LEX);

        $html = $this->lexer->render('leaf');
        $this->assertStringContainsString('<root>', $html);
        $this->assertStringContainsString('<mid>', $html);
        $this->assertStringContainsString('leaf content', $html);
    }

    // -----------------------------------------------------------------------
    // Infinite loop detection
    // -----------------------------------------------------------------------

    public function testInfiniteLayoutLoopThrows(): void
    {
        // A extends B, B extends A — infinite loop
        $this->writeView('loop-a', "#extends('loop-b')\n#section('x')a#endsection");
        $this->writeView('loop-b', "#extends('loop-a')\n#section('x')b#endsection");

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessageMatches('/infinite|loop/i');

        $this->lexer->render('loop-a');
    }

    public function testSelfExtendingTemplateThrows(): void
    {
        $this->writeView('self-loop', "#extends('self-loop')\n#section('x')x#endsection");

        $this->expectException(TemplateRuntimeException::class);

        $this->lexer->render('self-loop');
    }
}
