<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler;

use Wik\Lexer\Compiler\Node\BreakNode;
use Wik\Lexer\Compiler\Node\CheckEmptyNode;
use Wik\Lexer\Compiler\Node\ComponentNode;
use Wik\Lexer\Compiler\Node\ContinueNode;
use Wik\Lexer\Compiler\Node\EchoNode;
use Wik\Lexer\Compiler\Node\ExtendsNode;
use Wik\Lexer\Compiler\Node\ForEachNode;
use Wik\Lexer\Compiler\Node\ForNode;
use Wik\Lexer\Compiler\Node\IfNode;
use Wik\Lexer\Compiler\Node\IssetNode;
use Wik\Lexer\Compiler\Node\Node;
use Wik\Lexer\Compiler\Node\ParentNode;
use Wik\Lexer\Compiler\Node\PhpNode;
use Wik\Lexer\Compiler\Node\PushNode;
use Wik\Lexer\Compiler\Node\SectionNode;
use Wik\Lexer\Compiler\Node\SwitchNode;
use Wik\Lexer\Compiler\Node\UnlessNode;
use Wik\Lexer\Compiler\Node\WhileNode;
use Wik\Lexer\Exceptions\TemplateSyntaxException;
use Wik\Lexer\Security\ExpressionValidator;
use Wik\Lexer\Security\SandboxConfig;

/**
 * AST validation phase — runs after parsing, before code generation.
 *
 * Performs structural and semantic checks that cannot be done during parsing:
 *   - Sandbox mode enforcement (no raw echo, restricted functions)
 *   - Duplicate section detection within the same scope
 *   - Break/continue placement (must be inside a loop)
 *   - #parent placement (must be inside a section)
 *   - Extends placement (must be at root level, not inside blocks)
 *   - Expression validation via ExpressionValidator (sandbox mode)
 */
final class AstValidator
{
    public function __construct(
        private readonly SandboxConfig $sandboxConfig,
        private readonly ExpressionValidator $expressionValidator,
        private readonly string $templateFile = '',
    ) {
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Validate the full AST.
     *
     * @param Node[] $nodes
     *
     * @throws TemplateSyntaxException  on any validation failure
     */
    public function validate(array $nodes): void
    {
        $this->validateNodes($nodes, new ValidationContext());
    }

    // -----------------------------------------------------------------------
    // Internal recursive validation
    // -----------------------------------------------------------------------

    /**
     * @param Node[] $nodes
     */
    private function validateNodes(array $nodes, ValidationContext $ctx): void
    {
        $seenSections = [];

        foreach ($nodes as $node) {
            $this->validateNode($node, $ctx, $seenSections);
        }
    }

    /**
     * @param array<string, int> $seenSections
     */
    private function validateNode(Node $node, ValidationContext $ctx, array &$seenSections): void
    {
        match (true) {
            $node instanceof EchoNode        => $this->validateEchoNode($node, $ctx),
            $node instanceof SectionNode     => $this->validateSectionNode($node, $ctx, $seenSections),
            $node instanceof BreakNode       => $this->validateBreakContinue('break', $node->getLine(), $ctx),
            $node instanceof ContinueNode    => $this->validateBreakContinue('continue', $node->getLine(), $ctx),
            $node instanceof ParentNode      => $this->validateParentNode($node, $ctx),
            $node instanceof ExtendsNode     => $this->validateExtendsNode($node, $ctx),
            $node instanceof IfNode          => $this->validateIfNode($node, $ctx),
            $node instanceof ForEachNode     => $this->validateLoopNode($node, $node->children, $ctx),
            $node instanceof ForNode         => $this->validateLoopNode($node, $node->children, $ctx),
            $node instanceof WhileNode       => $this->validateLoopNode($node, $node->children, $ctx),
            $node instanceof UnlessNode      => $this->validateContainerNode($node->children, $ctx),
            $node instanceof IssetNode       => $this->validateContainerNode($node->children, $ctx),
            $node instanceof CheckEmptyNode  => $this->validateContainerNode($node->children, $ctx),
            $node instanceof SwitchNode      => $this->validateSwitchNode($node, $ctx),
            $node instanceof ComponentNode   => $this->validateComponentNode($node, $ctx),
            $node instanceof PushNode        => $this->validatePushNode($node, $ctx),
            $node instanceof PhpNode         => $this->validatePhpNode($node),
            default                          => null,
        };
    }

    // -----------------------------------------------------------------------
    // Per-node validators
    // -----------------------------------------------------------------------

    private function validateEchoNode(EchoNode $node, ValidationContext $ctx): void
    {
        if ($node->raw && !$this->sandboxConfig->allowRawEcho) {
            throw TemplateSyntaxException::sandboxViolation(
                'raw echo ({!! ... !!}) is not allowed in sandbox mode',
                $this->templateFile,
                $node->getLine(),
            );
        }

        if ($this->sandboxConfig->allowedFunctions !== null) {
            $this->expressionValidator->validate(
                $node->expression,
                $this->templateFile,
                $node->getLine(),
            );
        }
    }

    /**
     * @param array<string, int> $seenSections
     */
    private function validateSectionNode(
        SectionNode $node,
        ValidationContext $ctx,
        array &$seenSections,
    ): void {
        if (isset($seenSections[$node->name])) {
            throw TemplateSyntaxException::duplicateSection(
                $node->name,
                $this->templateFile,
                $node->getLine(),
            );
        }

        $seenSections[$node->name] = $node->getLine();

        $childCtx = $ctx->insideSection($node->name);
        $this->validateNodes($node->children, $childCtx);
    }

    private function validateBreakContinue(string $keyword, int $line, ValidationContext $ctx): void
    {
        if (!$ctx->inLoop) {
            throw new TemplateSyntaxException(
                "#{$keyword} used outside of a loop (#foreach or #while)",
                $this->templateFile,
                $line,
            );
        }
    }

    private function validateParentNode(ParentNode $node, ValidationContext $ctx): void
    {
        if (!$ctx->inSection) {
            throw new TemplateSyntaxException(
                '#parent may only be used inside a #section block',
                $this->templateFile,
                $node->getLine(),
            );
        }
    }

    private function validateExtendsNode(ExtendsNode $node, ValidationContext $ctx): void
    {
        if ($ctx->depth > 0) {
            throw new TemplateSyntaxException(
                '#extends must appear at the root level of the template, not inside a block',
                $this->templateFile,
                0,
            );
        }
    }

    private function validateIfNode(IfNode $node, ValidationContext $ctx): void
    {
        $childCtx = $ctx->nested();
        $this->validateNodes($node->children, $childCtx);

        foreach ($node->elseifBranches as $branch) {
            $this->validateNodes($branch['children'], $childCtx);
        }

        if ($node->elseChildren !== null) {
            $this->validateNodes($node->elseChildren, $childCtx);
        }
    }

    private function validateLoopNode(Node $node, array $children, ValidationContext $ctx): void
    {
        $childCtx = $ctx->nested()->withLoop();
        $this->validateNodes($children, $childCtx);
    }

    private function validateSwitchNode(SwitchNode $node, ValidationContext $ctx): void
    {
        $childCtx = $ctx->nested();

        foreach ($node->cases as $case) {
            $this->validateNodes($case['children'], $childCtx);
        }
    }

    private function validateComponentNode(ComponentNode $node, ValidationContext $ctx): void
    {
        $childCtx = $ctx->nested();
        $this->validateNodes($node->children, $childCtx);
    }

    private function validatePushNode(PushNode $node, ValidationContext $ctx): void
    {
        $childCtx = $ctx->nested();
        $this->validateNodes($node->children, $childCtx);
    }

    private function validateContainerNode(array $children, ValidationContext $ctx): void
    {
        $childCtx = $ctx->nested();
        $this->validateNodes($children, $childCtx);
    }

    private function validatePhpNode(PhpNode $node): void
    {
        if (!$this->sandboxConfig->allowRawEcho) {
            // Treat raw PHP blocks as a sandbox violation (same level as raw echo)
            throw TemplateSyntaxException::sandboxViolation(
                '#php blocks are not allowed in sandbox mode',
                $this->templateFile,
                $node->getLine(),
            );
        }
    }
}

/**
 * Immutable validation context threaded through the AST walk.
 */
final class ValidationContext
{
    public function __construct(
        public readonly bool $inLoop = false,
        public readonly bool $inSection = false,
        public readonly int $depth = 0,
        public readonly string $sectionName = '',
    ) {
    }

    public function nested(): self
    {
        return new self($this->inLoop, $this->inSection, $this->depth + 1, $this->sectionName);
    }

    public function withLoop(): self
    {
        return new self(true, $this->inSection, $this->depth, $this->sectionName);
    }

    public function insideSection(string $name): self
    {
        return new self($this->inLoop, true, $this->depth + 1, $name);
    }
}
