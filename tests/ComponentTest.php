<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Exceptions\TemplateRuntimeException;
use Wik\Lexer\Lexer;
use Wik\Lexer\Runtime\ComponentManager;

/**
 * Integration tests for the component system.
 *
 * Covers:
 *   - Self-closing components with literal and expression props
 *   - Components with default slot content
 *   - Named slots (<slot name="…">)
 *   - Dynamic prop binding (:prop="$expr")
 *   - Component class support (mount() + public properties)
 *   - Nested components
 *   - Recursion prevention (MAX_DEPTH)
 */
final class ComponentTest extends TestCase
{
    private string $viewDir;
    private string $cacheDir;
    private Lexer $lexer;

    protected function setUp(): void
    {
        $base           = sys_get_temp_dir() . '/lex_component_test_' . uniqid();
        $this->viewDir  = $base . '/views';
        $this->cacheDir = $base . '/cache';

        mkdir($this->viewDir . '/components', 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $this->lexer = (new Lexer())
            ->paths([$this->viewDir])
            ->cache($this->cacheDir)
            ->componentPath($this->viewDir . '/components');
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
        file_put_contents($this->viewDir . '/' . $name . '.lex', $content);
    }

    private function writeComponent(string $name, string $content): void
    {
        file_put_contents($this->viewDir . '/components/' . $name . '.lex', $content);
    }

    // -----------------------------------------------------------------------
    // Self-closing components
    // -----------------------------------------------------------------------

    public function testSelfClosingWithLiteralProp(): void
    {
        $this->writeComponent('badge', '<span class="badge">{{ $label }}</span>');
        $this->writeView('test', '<Badge label="New" />');

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('<span class="badge">New</span>', $html);
    }

    public function testSelfClosingWithExpressionProp(): void
    {
        $this->writeComponent('counter', '<span>{{ $value }}</span>');
        $this->writeView('test', '<Counter value={$count} />');

        $html = $this->lexer->render('test', ['count' => 42]);
        $this->assertStringContainsString('<span>42</span>', $html);
    }

    public function testSelfClosingWithDynamicColonProp(): void
    {
        $this->writeComponent('label', '<label>{{ $text }}</label>');
        $this->writeView('test', '<Label :text="strtoupper($word)" />');

        $html = $this->lexer->render('test', ['word' => 'hello']);
        $this->assertStringContainsString('<label>HELLO</label>', $html);
    }

    public function testSelfClosingWithBooleanProp(): void
    {
        $this->writeComponent('toggle', '<?php echo $active ? "on" : "off"; ?>');
        $this->writeView('test', '<Toggle active />');

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('on', $html);
    }

    // -----------------------------------------------------------------------
    // Default slot
    // -----------------------------------------------------------------------

    public function testComponentWithDefaultSlot(): void
    {
        $this->writeComponent('card', '<div class="card">{!! $slot !!}</div>');
        $this->writeView('test', "<Card>\n<p>body content</p>\n</Card>");

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('<div class="card">', $html);
        $this->assertStringContainsString('<p>body content</p>', $html);
    }

    public function testDefaultSlotEscapesWhenUsedWithEcho(): void
    {
        // Default slot is a plain string; use {{ $slot }} for escaped output
        $this->writeComponent('safe', '<div>{{ $slot }}</div>');
        $this->writeView('test', '<Safe><script>x</script></Safe>');

        $html = $this->lexer->render('test');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // -----------------------------------------------------------------------
    // Named slots
    // -----------------------------------------------------------------------

    public function testNamedSlotsAreAvailableInComponent(): void
    {
        $this->writeComponent('panel', <<<'LEX'
<div>
<header>{!! $slots['header'] ?? '' !!}</header>
<main>{!! $slot !!}</main>
<footer>{!! $slots['footer'] ?? '' !!}</footer>
</div>
LEX);

        $this->writeView('test', <<<'LEX'
<Panel>
<slot name="header"><h1>Title</h1></slot>
<p>Main content</p>
<slot name="footer"><p>Footer text</p></slot>
</Panel>
LEX);

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<p>Main content</p>', $html);
        $this->assertStringContainsString('<p>Footer text</p>', $html);
    }

    public function testMissingNamedSlotDefaultsToEmpty(): void
    {
        $this->writeComponent('box', '<div>{!! $slots["extra"] ?? "fallback" !!}</div>');
        $this->writeView('test', '<Box>content</Box>');

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('fallback', $html);
    }

    // -----------------------------------------------------------------------
    // Component class support
    // -----------------------------------------------------------------------

    public function testComponentClassMountIsCalledAndPropertiesInjected(): void
    {
        // Write a simple component class inline via eval for test isolation
        eval(<<<'PHP'
namespace Wik\Lexer\Tests\Components;

final class GreetingComponent {
    public string $message = '';
    public function mount(string $name = 'World'): void {
        $this->message = "Hello, {$name}!";
    }
}
PHP);

        $this->writeComponent('greeting', '<p>{{ $message }}</p>');

        $this->lexer->registerComponentClass(
            'Greeting',
            \Wik\Lexer\Tests\Components\GreetingComponent::class,
        );

        $this->writeView('test', '<Greeting name="Alice" />');

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('<p>Hello, Alice!</p>', $html);
    }

    public function testComponentClassDefaultPropsUsedWhenPropMissing(): void
    {
        eval(<<<'PHP'
namespace Wik\Lexer\Tests\Components;

final class TitleComponent {
    public string $title = '';
    public function mount(string $text = 'Default Title'): void {
        $this->title = $text;
    }
}
PHP);

        $this->writeComponent('title-comp', '<h1>{{ $title }}</h1>');

        $this->lexer->registerComponentClass(
            'TitleComp',
            \Wik\Lexer\Tests\Components\TitleComponent::class,
        );

        $this->writeView('test', '<TitleComp />');

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('<h1>Default Title</h1>', $html);
    }

    // -----------------------------------------------------------------------
    // Nested components
    // -----------------------------------------------------------------------

    public function testNestedComponentsRender(): void
    {
        $this->writeComponent('outer', '<div class="outer">{!! $slot !!}</div>');
        $this->writeComponent('inner', '<span class="inner">{{ $text }}</span>');
        $this->writeView('test', '<Outer><Inner text="nested" /></Outer>');

        $html = $this->lexer->render('test');
        $this->assertStringContainsString('<div class="outer">', $html);
        $this->assertStringContainsString('<span class="inner">nested</span>', $html);
    }

    // -----------------------------------------------------------------------
    // Recursion prevention
    // -----------------------------------------------------------------------

    public function testComponentRecursionLimitThrows(): void
    {
        // A component that renders itself — should hit MAX_DEPTH
        $this->writeComponent('recursive', '{!! $__env->renderComponent("Recursive", [], [], "") !!}');
        $this->lexer->component('Recursive', $this->viewDir . '/components/recursive.lex');

        $this->writeView('test', '<Recursive />');

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessageMatches('/recursion/i');

        $this->lexer->render('test');
    }

    public function testMaxDepthConstant(): void
    {
        $this->assertSame(50, ComponentManager::MAX_DEPTH);
    }
}
