<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Compiler\Lexer;
use Wik\Lexer\Compiler\Node\ComponentNode;
use Wik\Lexer\Compiler\Node\DirectiveNode;
use Wik\Lexer\Compiler\Node\EchoNode;
use Wik\Lexer\Compiler\Node\ExtendsNode;
use Wik\Lexer\Compiler\Node\ForEachNode;
use Wik\Lexer\Compiler\Node\IfNode;
use Wik\Lexer\Compiler\Node\SectionNode;
use Wik\Lexer\Compiler\Node\TextNode;
use Wik\Lexer\Compiler\Node\YieldNode;
use Wik\Lexer\Compiler\Parser;
use Wik\Lexer\Exceptions\ParseException;
use Wik\Lexer\Support\DirectiveRegistry;

final class ParserTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    private DirectiveRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new DirectiveRegistry();
        $this->lexer    = new Lexer();
        $this->parser   = new Parser($this->registry);
    }

    private function parse(string $source): array
    {
        return $this->parser->parse($this->lexer->tokenize($source));
    }

    // -----------------------------------------------------------------------
    // Leaf nodes
    // -----------------------------------------------------------------------

    public function testTextNodeParsed(): void
    {
        $nodes = $this->parse('<p>Hello</p>');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('<p>Hello</p>', $nodes[0]->text);
    }

    public function testEchoNodeParsed(): void
    {
        $nodes = $this->parse('{{ $name }}');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(EchoNode::class, $nodes[0]);
        $this->assertSame('$name', $nodes[0]->expression);
        $this->assertFalse($nodes[0]->raw);
    }

    public function testRawEchoNodeParsed(): void
    {
        $nodes = $this->parse('{!! $html !!}');

        $this->assertInstanceOf(EchoNode::class, $nodes[0]);
        $this->assertTrue($nodes[0]->raw);
    }

    // -----------------------------------------------------------------------
    // IfNode
    // -----------------------------------------------------------------------

    public function testSimpleIfBlock(): void
    {
        $source = "#if (\$x):\n<p>yes</p>\n#endif";
        $nodes  = $this->parse($source);

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(IfNode::class, $nodes[0]);
        $this->assertSame('$x', $nodes[0]->condition);
        $this->assertNotEmpty($nodes[0]->children);
        $this->assertEmpty($nodes[0]->elseifBranches);
        $this->assertNull($nodes[0]->elseChildren);
    }

    public function testIfElseBlock(): void
    {
        $source = "#if (\$x):\nyes\n#else\nno\n#endif";
        $nodes  = $this->parse($source);

        $ifNode = $nodes[0];
        $this->assertInstanceOf(IfNode::class, $ifNode);
        $this->assertNotNull($ifNode->elseChildren);
        $this->assertNotEmpty($ifNode->elseChildren);
    }

    public function testIfElseIfElseBlock(): void
    {
        $source = "#if (\$a):\nA\n#elseif (\$b):\nB\n#else\nC\n#endif";
        $nodes  = $this->parse($source);

        $ifNode = $nodes[0];
        $this->assertInstanceOf(IfNode::class, $ifNode);
        $this->assertCount(1, $ifNode->elseifBranches);
        $this->assertSame('$b', $ifNode->elseifBranches[0]['condition']);
        $this->assertNotNull($ifNode->elseChildren);
    }

    public function testMissingEndifThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unclosed if');

        $this->parse("#if (\$x):\n<p>content</p>");
    }

    public function testUnexpectedEndifThrows(): void
    {
        $this->expectException(ParseException::class);

        $this->parse('#endif');
    }

    // -----------------------------------------------------------------------
    // ForEachNode
    // -----------------------------------------------------------------------

    public function testForeachBlock(): void
    {
        $source = "#foreach (\$items as \$item):\n{{ \$item }}\n#endforeach";
        $nodes  = $this->parse($source);

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(ForEachNode::class, $nodes[0]);
        $this->assertSame('$items as $item', $nodes[0]->expression);
        $this->assertNotEmpty($nodes[0]->children);
    }

    public function testMissingEndforeachThrows(): void
    {
        $this->expectException(ParseException::class);

        $this->parse('#foreach ($items as $item):');
    }

    // -----------------------------------------------------------------------
    // SectionNode / ExtendsNode / YieldNode
    // -----------------------------------------------------------------------

    public function testSectionBlock(): void
    {
        $source = "#section('body')\n<p>content</p>\n#endsection";
        $nodes  = $this->parse($source);

        $this->assertInstanceOf(SectionNode::class, $nodes[0]);
        $this->assertSame('body', $nodes[0]->name);
        $this->assertNotEmpty($nodes[0]->children);
    }

    public function testExtendsNode(): void
    {
        $nodes = $this->parse("#extends('layouts.main')");

        $this->assertInstanceOf(ExtendsNode::class, $nodes[0]);
        $this->assertSame('layouts.main', $nodes[0]->layout);
    }

    public function testYieldNode(): void
    {
        $nodes = $this->parse("#yield('content')");

        $this->assertInstanceOf(YieldNode::class, $nodes[0]);
        $this->assertSame('content', $nodes[0]->name);
    }

    // -----------------------------------------------------------------------
    // ComponentNode
    // -----------------------------------------------------------------------

    public function testSelfClosingComponentNode(): void
    {
        $nodes = $this->parse('<Card title="Hello" />');

        $this->assertInstanceOf(ComponentNode::class, $nodes[0]);
        $this->assertSame('Card', $nodes[0]->name);
        $this->assertEmpty($nodes[0]->children);
        $this->assertArrayHasKey('title', $nodes[0]->props);
    }

    public function testOpenCloseComponentWithSlot(): void
    {
        $source = "<Card title=\"Hello\">\n<p>slot content</p>\n</Card>";
        $nodes  = $this->parse($source);

        $this->assertInstanceOf(ComponentNode::class, $nodes[0]);
        $this->assertNotEmpty($nodes[0]->children);
    }

    public function testMismatchedComponentCloseThrows(): void
    {
        $this->expectException(ParseException::class);

        $this->parse('<Card title="Hi"><p>text</p></Button>');
    }

    public function testUnexpectedComponentCloseThrows(): void
    {
        $this->expectException(ParseException::class);

        $this->parse('</Card>');
    }

    public function testUnclosedComponentThrows(): void
    {
        $this->expectException(ParseException::class);

        $this->parse('<Card title="Hi"><p>text</p>');
    }

    // -----------------------------------------------------------------------
    // Nested structures
    // -----------------------------------------------------------------------

    public function testNestedIfInsideForeach(): void
    {
        $source = <<<'LEX'
#foreach ($items as $item):
#if ($item > 0):
{{ $item }}
#endif
#endforeach
LEX;
        $nodes  = $this->parse($source);

        $this->assertInstanceOf(ForEachNode::class, $nodes[0]);
        $foreachChildren = $nodes[0]->children;

        $ifNodes = array_filter($foreachChildren, fn($n) => $n instanceof IfNode);
        $this->assertNotEmpty($ifNodes);
    }

    public function testComponentNestedInsideIf(): void
    {
        $source = <<<'LEX'
#if ($show):
<Alert type="info" />
#endif
LEX;
        $nodes  = $this->parse($source);

        $this->assertInstanceOf(IfNode::class, $nodes[0]);
        $componentNodes = array_filter($nodes[0]->children, fn($n) => $n instanceof ComponentNode);
        $this->assertNotEmpty($componentNodes);
    }

    // -----------------------------------------------------------------------
    // Custom directives
    // -----------------------------------------------------------------------

    public function testCustomDirectiveCreatesDirectiveNode(): void
    {
        $this->registry->register('upper', fn($expr) => "<?php echo strtoupper({$expr}); ?>");

        $nodes = $this->parse('#upper($name)');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(DirectiveNode::class, $nodes[0]);
        $this->assertSame('upper', $nodes[0]->name);
        $this->assertStringContainsString('strtoupper', $nodes[0]->compiledOutput);
    }

    public function testUnknownDirectiveThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unknown directive');

        $this->parse('#unknownDirective($x)');
    }
}
