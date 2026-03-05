<?php

declare(strict_types=1);

namespace Wik\Lexer\Compiler;

use Wik\Lexer\Compiler\Node\BreakNode;
use Wik\Lexer\Compiler\Node\CheckEmptyNode;
use Wik\Lexer\Compiler\Node\ComponentNode;
use Wik\Lexer\Compiler\Node\ContinueNode;
use Wik\Lexer\Compiler\Node\DirectiveNode;
use Wik\Lexer\Compiler\Node\EchoNode;
use Wik\Lexer\Compiler\Node\ExtendsNode;
use Wik\Lexer\Compiler\Node\ForEachNode;
use Wik\Lexer\Compiler\Node\ForNode;
use Wik\Lexer\Compiler\Node\IfNode;
use Wik\Lexer\Compiler\Node\IncludeNode;
use Wik\Lexer\Compiler\Node\IssetNode;
use Wik\Lexer\Compiler\Node\Node;
use Wik\Lexer\Compiler\Node\ParentNode;
use Wik\Lexer\Compiler\Node\PhpNode;
use Wik\Lexer\Compiler\Node\PushNode;
use Wik\Lexer\Compiler\Node\SectionNode;
use Wik\Lexer\Compiler\Node\StackNode;
use Wik\Lexer\Compiler\Node\SwitchNode;
use Wik\Lexer\Compiler\Node\TextNode;
use Wik\Lexer\Compiler\Node\UnlessNode;
use Wik\Lexer\Compiler\Node\WhileNode;
use Wik\Lexer\Compiler\Node\YieldNode;
use Wik\Lexer\Exceptions\ParseException;
use Wik\Lexer\Support\DirectiveRegistry;

/**
 * Converts a flat Token stream into a nested AST using an explicit stack.
 *
 * Stack frame shape:
 * [
 *   'type'     => string,   // 'root'|'if'|'foreach'|'for'|'while'|'unless'|'isset'|'empty_check'|'switch'|'section'|'component'|'push'
 *   'children' => Node[],   // nodes accumulated in this scope
 *   'extras'   => array,    // block-specific metadata
 * ]
 */
final class Parser
{
    private array $tokens = [];
    private int $pos      = 0;

    /** @var array<int, array{type: string, children: Node[], extras: array}> */
    private array $stack = [];

    /** Maximum nesting depth to prevent stack overflows from pathological templates */
    private const MAX_NESTING_DEPTH = 100;

    private const BUILT_IN = [
        'if', 'elseif', 'else', 'endif',
        'foreach', 'endforeach',
        'for', 'endfor',
        'while', 'endwhile',
        'unless', 'endunless',
        'isset', 'endisset',
        'empty', 'endempty',
        'switch', 'case', 'default', 'endswitch',
        'break', 'continue',
        'section', 'endsection',
        'extends', 'yield',
        'push', 'endpush',
        'stack',
        'parent',
        'include', 'includeIf', 'includeWhen', 'includeFirst',
        'dump', 'dd',
    ];

    public function __construct(
        private readonly DirectiveRegistry $registry,
    ) {
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Parse a token stream and return the root-level AST node array.
     *
     * @param  Token[] $tokens
     * @return Node[]
     *
     * @throws ParseException
     */
    public function parse(array $tokens): array
    {
        $this->tokens = $tokens;
        $this->pos    = 0;
        $this->stack  = [
            ['type' => 'root', 'children' => [], 'extras' => []],
        ];

        while ($this->pos < count($this->tokens)) {
            $this->processToken($this->tokens[$this->pos++]);
        }

        if (count($this->stack) > 1) {
            $frame = end($this->stack);
            $line  = $frame['extras']['line'] ?? 0;
            throw ParseException::unclosedBlock($frame['type'], $line);
        }

        return $this->stack[0]['children'];
    }

    // -----------------------------------------------------------------------
    // Token dispatch
    // -----------------------------------------------------------------------

    private function processToken(Token $token): void
    {
        match ($token->type) {
            Token::T_TEXT            => $this->addNode(new TextNode($token->value, $token->line)),
            Token::T_ECHO            => $this->addNode(new EchoNode($token->expression ?? '', false, $token->line)),
            Token::T_RAW_ECHO        => $this->addNode(new EchoNode($token->expression ?? '', true, $token->line)),
            Token::T_DIRECTIVE       => $this->processDirective($token),
            Token::T_PHP_BLOCK       => $this->addNode(new PhpNode($token->expression ?? '', $token->line)),
            Token::T_COMPONENT_SELF  => $this->addNode(new ComponentNode($token->name ?? '', $token->props, [], $token->line)),
            Token::T_COMPONENT_OPEN  => $this->pushComponentFrame($token),
            Token::T_COMPONENT_CLOSE => $this->popComponentFrame($token),
            default                  => null,
        };
    }

    // -----------------------------------------------------------------------
    // Directive routing
    // -----------------------------------------------------------------------

    private function processDirective(Token $token): void
    {
        $name = $token->name ?? '';

        match ($name) {
            // Conditionals
            'if'           => $this->handleIf($token),
            'elseif'       => $this->handleElseIf($token),
            'else'         => $this->handleElse($token),
            'endif'        => $this->handleEndIf($token),
            // Loops
            'foreach'      => $this->handleForeach($token),
            'endforeach'   => $this->handleEndForeach($token),
            'for'          => $this->handleFor($token),
            'endfor'       => $this->handleEndFor($token),
            'while'        => $this->handleWhile($token),
            'endwhile'     => $this->handleEndWhile($token),
            // Inverse conditional
            'unless'       => $this->handleUnless($token),
            'endunless'    => $this->handleEndUnless($token),
            // Existence checks
            'isset'        => $this->handleIsset($token),
            'endisset'     => $this->handleEndIsset($token),
            'empty'        => $this->handleEmpty($token),
            'endempty'     => $this->handleEndEmpty($token),
            // Switch
            'switch'       => $this->handleSwitch($token),
            'case'         => $this->handleCase($token),
            'default'      => $this->handleDefault($token),
            'endswitch'    => $this->handleEndSwitch($token),
            // Flow control
            'break'        => $this->handleBreak($token),
            'continue'     => $this->handleContinue($token),
            // Layout
            'section'      => $this->handleSection($token),
            'endsection'   => $this->handleEndSection($token),
            'extends'      => $this->handleExtends($token),
            'yield'        => $this->handleYield($token),
            // Push stacks
            'push'         => $this->handlePush($token),
            'endpush'      => $this->handleEndPush($token),
            'stack'        => $this->handleStack($token),
            // Parent section
            'parent'       => $this->handleParent($token),
            // Includes
            'include'      => $this->handleInclude($token, 'include'),
            'includeIf'    => $this->handleInclude($token, 'includeIf'),
            'includeWhen'  => $this->handleInclude($token, 'includeWhen'),
            'includeFirst' => $this->handleInclude($token, 'includeFirst'),
            // Debug
            'dump'         => $this->handleDump($token),
            'dd'           => $this->handleDd($token),
            // Custom
            default        => $this->handleCustomDirective($token),
        };
    }

    // -----------------------------------------------------------------------
    // #if / #elseif / #else / #endif
    // -----------------------------------------------------------------------

    private function handleIf(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'if',
            'children' => [],
            'extras'   => [
                'condition'      => $token->expression ?? 'true',
                'current_branch' => 'if',
                'branches'       => [],
                'line'           => $token->line,
            ],
        ];
    }

    private function handleElseIf(Token $token): void
    {
        $idx = $this->requireTopFrame('if', $token);

        $this->stack[$idx]['extras']['branches'][] = [
            'condition' => $this->stack[$idx]['extras']['condition'],
            'children'  => $this->stack[$idx]['children'],
            'branch'    => $this->stack[$idx]['extras']['current_branch'],
        ];

        $this->stack[$idx]['extras']['condition']      = $token->expression ?? 'true';
        $this->stack[$idx]['extras']['current_branch'] = 'elseif';
        $this->stack[$idx]['children']                 = [];
    }

    private function handleElse(Token $token): void
    {
        $idx = $this->requireTopFrame('if', $token);

        $this->stack[$idx]['extras']['branches'][] = [
            'condition' => $this->stack[$idx]['extras']['condition'],
            'children'  => $this->stack[$idx]['children'],
            'branch'    => $this->stack[$idx]['extras']['current_branch'],
        ];

        $this->stack[$idx]['extras']['current_branch'] = 'else';
        $this->stack[$idx]['children']                 = [];
    }

    private function handleEndIf(Token $token): void
    {
        $this->requireTopFrame('if', $token);

        $frame         = array_pop($this->stack);
        $allBranches   = $frame['extras']['branches'];
        $allBranches[] = [
            'condition' => $frame['extras']['condition'],
            'children'  => $frame['children'],
            'branch'    => $frame['extras']['current_branch'],
        ];

        $this->addNode($this->buildIfNode($allBranches));
    }

    /**
     * @param array<int, array{condition: string, children: Node[], branch: string}> $branches
     */
    private function buildIfNode(array $branches): IfNode
    {
        $primary        = array_shift($branches);
        $elseifBranches = [];
        $elseChildren   = null;

        foreach ($branches as $branch) {
            if ($branch['branch'] === 'else') {
                $elseChildren = $branch['children'];
            } else {
                $elseifBranches[] = [
                    'condition' => $branch['condition'],
                    'children'  => $branch['children'],
                ];
            }
        }

        return new IfNode(
            $primary['condition'],
            $primary['children'],
            $elseifBranches,
            $elseChildren,
        );
    }

    // -----------------------------------------------------------------------
    // #foreach / #endforeach
    // -----------------------------------------------------------------------

    private function handleForeach(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'foreach',
            'children' => [],
            'extras'   => [
                'expression' => $token->expression ?? '',
                'line'       => $token->line,
            ],
        ];
    }

    private function handleEndForeach(Token $token): void
    {
        $this->requireTopFrame('foreach', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new ForEachNode($frame['extras']['expression'], $frame['children']));
    }

    // -----------------------------------------------------------------------
    // #for / #endfor
    // -----------------------------------------------------------------------

    private function handleFor(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'for',
            'children' => [],
            'extras'   => [
                'expression' => $token->expression ?? '',
                'line'       => $token->line,
            ],
        ];
    }

    private function handleEndFor(Token $token): void
    {
        $this->requireTopFrame('for', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new ForNode($frame['extras']['expression'], $frame['children'], $frame['extras']['line']));
    }

    // -----------------------------------------------------------------------
    // #while / #endwhile
    // -----------------------------------------------------------------------

    private function handleWhile(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'while',
            'children' => [],
            'extras'   => [
                'condition' => $token->expression ?? 'false',
                'line'      => $token->line,
            ],
        ];
    }

    private function handleEndWhile(Token $token): void
    {
        $this->requireTopFrame('while', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new WhileNode($frame['extras']['condition'], $frame['children']));
    }

    // -----------------------------------------------------------------------
    // #unless / #endunless
    // -----------------------------------------------------------------------

    private function handleUnless(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'unless',
            'children' => [],
            'extras'   => [
                'condition' => $token->expression ?? 'true',
                'line'      => $token->line,
            ],
        ];
    }

    private function handleEndUnless(Token $token): void
    {
        $this->requireTopFrame('unless', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new UnlessNode($frame['extras']['condition'], $frame['children'], $frame['extras']['line']));
    }

    // -----------------------------------------------------------------------
    // #isset / #endisset
    // -----------------------------------------------------------------------

    private function handleIsset(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'isset',
            'children' => [],
            'extras'   => [
                'expression' => $token->expression ?? '',
                'line'       => $token->line,
            ],
        ];
    }

    private function handleEndIsset(Token $token): void
    {
        $this->requireTopFrame('isset', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new IssetNode($frame['extras']['expression'], $frame['children'], $frame['extras']['line']));
    }

    // -----------------------------------------------------------------------
    // #empty / #endempty
    // -----------------------------------------------------------------------

    private function handleEmpty(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'empty_check',
            'children' => [],
            'extras'   => [
                'expression' => $token->expression ?? '',
                'line'       => $token->line,
            ],
        ];
    }

    private function handleEndEmpty(Token $token): void
    {
        $this->requireTopFrame('empty_check', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new CheckEmptyNode($frame['extras']['expression'], $frame['children'], $frame['extras']['line']));
    }

    // -----------------------------------------------------------------------
    // #switch / #case / #default / #endswitch
    // -----------------------------------------------------------------------

    private function handleSwitch(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'switch',
            'children' => [],
            'extras'   => [
                'expression'    => $token->expression ?? '',
                'cases'         => [],
                'current_value' => null,
                'in_case'       => false,
                'line'          => $token->line,
            ],
        ];
    }

    private function handleCase(Token $token): void
    {
        $idx = $this->requireTopFrame('switch', $token);

        if ($this->stack[$idx]['extras']['in_case']) {
            $this->stack[$idx]['extras']['cases'][] = [
                'value'    => $this->stack[$idx]['extras']['current_value'],
                'children' => $this->stack[$idx]['children'],
            ];
        }

        $this->stack[$idx]['extras']['current_value'] = $token->expression;
        $this->stack[$idx]['extras']['in_case']       = true;
        $this->stack[$idx]['children']                = [];
    }

    private function handleDefault(Token $token): void
    {
        $idx = $this->requireTopFrame('switch', $token);

        if ($this->stack[$idx]['extras']['in_case']) {
            $this->stack[$idx]['extras']['cases'][] = [
                'value'    => $this->stack[$idx]['extras']['current_value'],
                'children' => $this->stack[$idx]['children'],
            ];
        }

        $this->stack[$idx]['extras']['current_value'] = null;
        $this->stack[$idx]['extras']['in_case']       = true;
        $this->stack[$idx]['children']                = [];
    }

    private function handleEndSwitch(Token $token): void
    {
        $this->requireTopFrame('switch', $token);
        $frame = array_pop($this->stack);

        $cases = $frame['extras']['cases'];

        if ($frame['extras']['in_case']) {
            $cases[] = [
                'value'    => $frame['extras']['current_value'],
                'children' => $frame['children'],
            ];
        }

        $this->addNode(new SwitchNode($frame['extras']['expression'], $cases));
    }

    // -----------------------------------------------------------------------
    // #break / #continue
    // -----------------------------------------------------------------------

    private function handleBreak(Token $token): void
    {
        $levels = $this->parseNumericExpression($token->expression, 1);
        $this->addNode(new BreakNode($levels));
    }

    private function handleContinue(Token $token): void
    {
        $levels = $this->parseNumericExpression($token->expression, 1);
        $this->addNode(new ContinueNode($levels));
    }

    // -----------------------------------------------------------------------
    // #section / #endsection
    // -----------------------------------------------------------------------

    private function handleSection(Token $token): void
    {
        $name = $this->stripQuotes($token->expression ?? '');

        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'section',
            'children' => [],
            'extras'   => [
                'name' => $name,
                'line' => $token->line,
            ],
        ];
    }

    private function handleEndSection(Token $token): void
    {
        $this->requireTopFrame('section', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new SectionNode($frame['extras']['name'], $frame['children']));
    }

    // -----------------------------------------------------------------------
    // #extends / #yield
    // -----------------------------------------------------------------------

    private function handleExtends(Token $token): void
    {
        $layout = $this->stripQuotes($token->expression ?? '');
        $this->addNode(new ExtendsNode($layout));
    }

    private function handleYield(Token $token): void
    {
        // #yield('name') or #yield('name', 'default') or #yield('name', $expr)
        $expr = $token->expression ?? '';

        // Split on first unquoted comma
        [$name, $default, $isExpr] = $this->parseYieldExpression($expr);

        $this->addNode(new YieldNode($name, $default, $isExpr, $token->line));
    }

    // -----------------------------------------------------------------------
    // #push / #endpush / #stack
    // -----------------------------------------------------------------------

    private function handlePush(Token $token): void
    {
        $name = $this->stripQuotes($token->expression ?? '');

        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'push',
            'children' => [],
            'extras'   => [
                'name' => $name,
                'line' => $token->line,
            ],
        ];
    }

    private function handleEndPush(Token $token): void
    {
        $this->requireTopFrame('push', $token);
        $frame = array_pop($this->stack);
        $this->addNode(new PushNode($frame['extras']['name'], $frame['children'], $frame['extras']['line']));
    }

    private function handleStack(Token $token): void
    {
        // #stack('name') or #stack('name', 'default')
        $expr = $token->expression ?? '';

        [$name, $default] = $this->splitTwoArgs($expr);

        $this->addNode(new StackNode($name, $default, $token->line));
    }

    // -----------------------------------------------------------------------
    // #parent
    // -----------------------------------------------------------------------

    private function handleParent(Token $token): void
    {
        $this->addNode(new ParentNode($token->line));
    }

    // -----------------------------------------------------------------------
    // #include / #includeIf / #includeWhen / #includeFirst
    // -----------------------------------------------------------------------

    private function handleInclude(Token $token, string $method): void
    {
        $this->addNode(new IncludeNode($method, $token->expression ?? '', $token->line));
    }

    // -----------------------------------------------------------------------
    // #dump / #dd
    // -----------------------------------------------------------------------

    private function handleDump(Token $token): void
    {
        $expr     = $token->expression ?? '';
        $compiled = '<?php var_dump(' . $expr . '); ?>';
        $this->addNode(new DirectiveNode('dump', $expr, $compiled, $token->line));
    }

    private function handleDd(Token $token): void
    {
        $expr     = $token->expression ?? '';
        $compiled = '<?php var_dump(' . $expr . '); exit(1); ?>';
        $this->addNode(new DirectiveNode('dd', $expr, $compiled, $token->line));
    }

    // -----------------------------------------------------------------------
    // Custom directives (user-registered)
    // -----------------------------------------------------------------------

    private function handleCustomDirective(Token $token): void
    {
        $name = $token->name ?? '';

        if (!$this->registry->has($name)) {
            throw ParseException::unknownDirective($name, $token->line);
        }

        $expression     = $token->expression ?? '';
        $compiledOutput = $this->registry->compile($name, $expression);

        $this->addNode(new DirectiveNode($name, $expression, $compiledOutput, $token->line));
    }

    // -----------------------------------------------------------------------
    // Component frame management
    // -----------------------------------------------------------------------

    private function pushComponentFrame(Token $token): void
    {
        $this->checkDepth($token);
        $this->stack[] = [
            'type'     => 'component',
            'children' => [],
            'extras'   => [
                'name'  => $token->name ?? '',
                'props' => $token->props,
                'line'  => $token->line,
            ],
        ];
    }

    private function popComponentFrame(Token $token): void
    {
        $closeName = $token->name ?? '';
        $topFrame  = end($this->stack);

        if ($topFrame === false || $topFrame['type'] !== 'component') {
            throw ParseException::unexpectedClosingTag($closeName, $token->line);
        }

        $openName = $topFrame['extras']['name'];

        if (strcasecmp($openName, $closeName) !== 0) {
            throw ParseException::mismatchedClosingTag($openName, $closeName, $token->line);
        }

        $frame = array_pop($this->stack);
        $this->addNode(new ComponentNode(
            $frame['extras']['name'],
            $frame['extras']['props'],
            $frame['children'],
            $frame['extras']['line'],
        ));
    }

    // -----------------------------------------------------------------------
    // Stack helpers
    // -----------------------------------------------------------------------

    private function addNode(Node $node): void
    {
        $this->stack[count($this->stack) - 1]['children'][] = $node;
    }

    /**
     * @throws ParseException
     */
    private function requireTopFrame(string $expectedType, Token $token): int
    {
        $idx      = count($this->stack) - 1;
        $topFrame = $this->stack[$idx];

        if ($topFrame['type'] !== $expectedType) {
            throw ParseException::unexpectedDirective($token->name ?? '', $token->line);
        }

        return $idx;
    }

    /**
     * Guard against pathologically deeply nested templates.
     *
     * @throws ParseException
     */
    private function checkDepth(Token $token): void
    {
        if (count($this->stack) >= self::MAX_NESTING_DEPTH) {
            throw new ParseException(
                'Template nesting depth exceeds the maximum of ' . self::MAX_NESTING_DEPTH
                . ' at line ' . $token->line . '. Simplify the template structure.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    private function stripQuotes(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    private function parseNumericExpression(?string $expression, int $default): int
    {
        if ($expression === null || trim($expression) === '') {
            return $default;
        }

        $val = (int) trim($expression);

        return $val > 0 ? $val : $default;
    }

    /**
     * Parse #yield('name') or #yield('name', 'default') or #yield('name', $expr).
     *
     * @return array{string, string, bool}  [name, default, defaultIsExpr]
     */
    private function parseYieldExpression(string $expr): array
    {
        // Find first comma not inside quotes or parens
        $depth    = 0;
        $inString = false;
        $strChar  = '';
        $splitAt  = null;

        for ($i = 0; $i < strlen($expr); $i++) {
            $c = $expr[$i];

            if ($inString) {
                if ($c === '\\') {
                    $i++;
                    continue;
                }
                if ($c === $strChar) {
                    $inString = false;
                }
                continue;
            }

            if ($c === '"' || $c === "'") {
                $inString = true;
                $strChar  = $c;
                continue;
            }

            if ($c === '(' || $c === '[') {
                $depth++;
                continue;
            }

            if ($c === ')' || $c === ']') {
                $depth--;
                continue;
            }

            if ($c === ',' && $depth === 0) {
                $splitAt = $i;
                break;
            }
        }

        if ($splitAt === null) {
            return [$this->stripQuotes($expr), '', false];
        }

        $namePart    = trim(substr($expr, 0, $splitAt));
        $defaultPart = trim(substr($expr, $splitAt + 1));

        $name      = $this->stripQuotes($namePart);
        $isLiteral = (strlen($defaultPart) >= 2)
            && (($defaultPart[0] === "'" && $defaultPart[-1] === "'")
                || ($defaultPart[0] === '"' && $defaultPart[-1] === '"'));

        if ($isLiteral) {
            return [$name, substr($defaultPart, 1, -1), false];
        }

        return [$name, $defaultPart, true];
    }

    /**
     * Split a two-argument expression like "'name', 'default'".
     *
     * @return array{string, string}
     */
    private function splitTwoArgs(string $expr): array
    {
        [$name, $default] = $this->parseYieldExpression($expr);

        return [$name, $default];
    }
}
