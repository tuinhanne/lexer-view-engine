<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Compiler\Lexer;
use Wik\Lexer\Compiler\Token;
use Wik\Lexer\Exceptions\LexerException;

final class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    // -----------------------------------------------------------------------
    // Plain text
    // -----------------------------------------------------------------------

    public function testPlainTextProducesSingleTextToken(): void
    {
        $tokens = $this->lexer->tokenize('<p>Hello World</p>');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_TEXT, $tokens[0]->type);
        $this->assertSame('<p>Hello World</p>', $tokens[0]->value);
    }

    public function testEmptySourceProducesNoTokens(): void
    {
        $tokens = $this->lexer->tokenize('');

        $this->assertCount(0, $tokens);
    }

    // -----------------------------------------------------------------------
    // Echo tokens
    // -----------------------------------------------------------------------

    public function testEscapedEchoTokenIsProduced(): void
    {
        $tokens = $this->lexer->tokenize('{{ $name }}');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_ECHO, $tokens[0]->type);
        $this->assertSame('$name', $tokens[0]->expression);
    }

    public function testEchoWithSurroundingText(): void
    {
        $tokens = $this->lexer->tokenize('Hello {{ $name }}!');

        $this->assertCount(3, $tokens);
        $this->assertSame(Token::T_TEXT, $tokens[0]->type);
        $this->assertSame(Token::T_ECHO, $tokens[1]->type);
        $this->assertSame(Token::T_TEXT, $tokens[2]->type);
        $this->assertSame('Hello ', $tokens[0]->value);
        $this->assertSame('$name', $tokens[1]->expression);
        $this->assertSame('!', $tokens[2]->value);
    }

    public function testEchoExpressionIsTrimmed(): void
    {
        $tokens = $this->lexer->tokenize('{{   $foo   }}');

        $this->assertSame('$foo', $tokens[0]->expression);
    }

    public function testNestedBracesInsideEcho(): void
    {
        $tokens = $this->lexer->tokenize('{{ array_key_exists(\'k\', [\'k\' => 1]) }}');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_ECHO, $tokens[0]->type);
    }

    // -----------------------------------------------------------------------
    // Raw echo tokens
    // -----------------------------------------------------------------------

    public function testRawEchoTokenIsProduced(): void
    {
        $tokens = $this->lexer->tokenize('{!! $html !!}');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_RAW_ECHO, $tokens[0]->type);
        $this->assertSame('$html', $tokens[0]->expression);
    }

    public function testRawEchoWithSurroundingText(): void
    {
        $tokens = $this->lexer->tokenize('before {!! $raw !!} after');

        $this->assertCount(3, $tokens);
        $this->assertSame(Token::T_TEXT, $tokens[0]->type);
        $this->assertSame(Token::T_RAW_ECHO, $tokens[1]->type);
        $this->assertSame(Token::T_TEXT, $tokens[2]->type);
    }

    // -----------------------------------------------------------------------
    // Directive tokens
    // -----------------------------------------------------------------------

    public function testIfDirectiveTokenIsProduced(): void
    {
        $tokens = $this->lexer->tokenize('#if ($x > 0):');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_DIRECTIVE, $tokens[0]->type);
        $this->assertSame('if', $tokens[0]->name);
        $this->assertSame('$x > 0', $tokens[0]->expression);
    }

    public function testEndifDirectiveHasNoExpression(): void
    {
        $tokens = $this->lexer->tokenize('#endif');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_DIRECTIVE, $tokens[0]->type);
        $this->assertSame('endif', $tokens[0]->name);
        $this->assertNull($tokens[0]->expression);
    }

    public function testForeachDirective(): void
    {
        $tokens = $this->lexer->tokenize('#foreach ($items as $item):');

        $this->assertSame('foreach', $tokens[0]->name);
        $this->assertSame('$items as $item', $tokens[0]->expression);
    }

    public function testExtendsDirective(): void
    {
        $tokens = $this->lexer->tokenize("#extends('layouts.main')");

        $this->assertSame('extends', $tokens[0]->name);
        $this->assertSame("'layouts.main'", $tokens[0]->expression);
    }

    public function testSectionDirective(): void
    {
        $tokens = $this->lexer->tokenize("#section('content')");

        $this->assertSame('section', $tokens[0]->name);
        $this->assertSame("'content'", $tokens[0]->expression);
    }

    public function testYieldDirective(): void
    {
        $tokens = $this->lexer->tokenize("#yield('content')");

        $this->assertSame('yield', $tokens[0]->name);
        $this->assertSame("'content'", $tokens[0]->expression);
    }

    public function testDirectiveNestedParens(): void
    {
        $tokens = $this->lexer->tokenize('#datetime(date("Y", time()))');

        $this->assertSame('datetime', $tokens[0]->name);
        $this->assertStringContainsString('time()', $tokens[0]->expression ?? '');
    }

    public function testLineNumberTracking(): void
    {
        $source = "line one\n{{ \$x }}\nline three";
        $tokens = $this->lexer->tokenize($source);

        $echoToken = null;
        foreach ($tokens as $t) {
            if ($t->type === Token::T_ECHO) {
                $echoToken = $t;
                break;
            }
        }

        $this->assertNotNull($echoToken);
        $this->assertSame(2, $echoToken->line);
    }

    // -----------------------------------------------------------------------
    // Component tokens
    // -----------------------------------------------------------------------

    public function testSelfClosingComponentToken(): void
    {
        $tokens = $this->lexer->tokenize('<Card title="Hello" />');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_COMPONENT_SELF, $tokens[0]->type);
        $this->assertSame('Card', $tokens[0]->name);
        $this->assertArrayHasKey('title', $tokens[0]->props);
        $this->assertSame('literal', $tokens[0]->props['title']['type']);
        $this->assertSame('Hello', $tokens[0]->props['title']['value']);
    }

    public function testOpenComponentToken(): void
    {
        $tokens = $this->lexer->tokenize('<Card title="Hello">');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_COMPONENT_OPEN, $tokens[0]->type);
        $this->assertSame('Card', $tokens[0]->name);
    }

    public function testCloseComponentToken(): void
    {
        $tokens = $this->lexer->tokenize('</Card>');

        $this->assertCount(1, $tokens);
        $this->assertSame(Token::T_COMPONENT_CLOSE, $tokens[0]->type);
        $this->assertSame('Card', $tokens[0]->name);
    }

    public function testComponentWithPhpExpressionProp(): void
    {
        $tokens = $this->lexer->tokenize('<Card active={$isActive} />');

        $this->assertSame(Token::T_COMPONENT_SELF, $tokens[0]->type);
        $this->assertArrayHasKey('active', $tokens[0]->props);
        $this->assertSame('expression', $tokens[0]->props['active']['type']);
        $this->assertSame('$isActive', $tokens[0]->props['active']['value']);
    }

    public function testComponentWithBooleanProp(): void
    {
        $tokens = $this->lexer->tokenize('<Button disabled />');

        $this->assertSame(Token::T_COMPONENT_SELF, $tokens[0]->type);
        $this->assertArrayHasKey('disabled', $tokens[0]->props);
        $this->assertSame('boolean', $tokens[0]->props['disabled']['type']);
        $this->assertTrue($tokens[0]->props['disabled']['value']);
    }

    public function testComponentWithMultipleProps(): void
    {
        $tokens = $this->lexer->tokenize('<Alert type="success" dismissible />');

        $props = $tokens[0]->props;
        $this->assertArrayHasKey('type', $props);
        $this->assertArrayHasKey('dismissible', $props);
        $this->assertSame('success', $props['type']['value']);
        $this->assertTrue($props['dismissible']['value']);
    }

    // -----------------------------------------------------------------------
    // Mixed content
    // -----------------------------------------------------------------------

    public function testComplexMixedSource(): void
    {
        $source = <<<'LEX'
<h1>{{ $title }}</h1>
#if ($show):
<p>{!! $body !!}</p>
#endif
LEX;
        $tokens = $this->lexer->tokenize($source);

        $types = array_column($tokens, 'type');
        $this->assertContains(Token::T_TEXT, $types);
        $this->assertContains(Token::T_ECHO, $types);
        $this->assertContains(Token::T_RAW_ECHO, $types);
        $this->assertContains(Token::T_DIRECTIVE, $types);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function testUnterminatedEchoThrows(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unterminated echo');

        $this->lexer->tokenize('{{ $name ');
    }

    public function testUnterminatedRawEchoThrows(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unterminated raw echo');

        $this->lexer->tokenize('{!! $name ');
    }
}
