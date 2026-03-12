<?php

declare(strict_types=1);

namespace Wik\Lexer\Security;

use Wik\Lexer\Exceptions\TemplateSyntaxException;

/**
 * Validates PHP expression strings against a SandboxConfig.
 *
 * The validator uses a multi-pass approach:
 *   1. Strip string literals to avoid false positive keyword matches
 *   2. Check for always-blocked dangerous constructs
 *   3. If a function whitelist is configured, extract all called function names
 *      and assert they are all in the whitelist
 *
 * This is not a full PHP parser — it uses regex heuristics which are safe for
 * template expressions but may produce false positives for very unusual code
 * patterns.  For a full sandbox, consider running templates in a separate
 * PHP process.
 */
final class ExpressionValidator
{
    /**
     * Function names that are ALWAYS forbidden, regardless of sandbox config.
     * These functions can execute arbitrary OS commands or read/write files.
     *
     * @var string[]
     */
    private const ALWAYS_BLOCKED = [
        // Code execution
        'eval', 'assert', 'create_function', 'preg_replace_callback_array',
        // OS command execution
        'exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen',
        'pcntl_exec',
        // File system
        'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'fread',
        'fclose', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'glob',
        'readfile', 'include', 'include_once', 'require', 'require_once',
        // Network
        'fsockopen', 'curl_exec', 'curl_multi_exec',
        // Dynamic function calls
        'call_user_func', 'call_user_func_array', 'forward_static_call',
        'forward_static_call_array',
        // Reflection & class loading
        'class_alias',
        // Output / session
        'header', 'setcookie', 'session_start', 'session_destroy',
    ];

    public function __construct(
        private readonly SandboxConfig $config,
    ) {
    }

    /**
     * Validate the expression string.
     *
     * @throws TemplateSyntaxException  on any detected violation
     */
    public function validate(
        string $expression,
        string $templateFile = '',
        int $line = 0,
    ): void {
        if (trim($expression) === '') {
            return;
        }

        // Backtick shell execution is always forbidden
        if (str_contains($expression, '`')) {
            throw TemplateSyntaxException::sandboxViolation(
                'backtick execution operator (`) is not allowed',
                $templateFile,
                $line,
            );
        }

        // Strip string literals to avoid false positives inside strings
        $stripped = $this->stripStringLiterals($expression);

        // Check always-blocked functions
        foreach (self::ALWAYS_BLOCKED as $fn) {
            if ($this->containsFunctionCall($stripped, $fn)) {
                throw TemplateSyntaxException::invalidExpression(
                    $expression,
                    "function '{$fn}' is forbidden",
                    $templateFile,
                    $line,
                );
            }
        }

        // In strict sandbox: block new keyword before function whitelist check
        // so `new stdClass()` reports "object instantiation" not "function not allowed"
        if ($this->config->allowedFunctions !== null) {
            if (preg_match('/\bnew\s+[A-Z]/i', $stripped)) {
                throw TemplateSyntaxException::sandboxViolation(
                    'object instantiation (new) is not allowed',
                    $templateFile,
                    $line,
                );
            }
        }

        // If a function whitelist is configured, validate all function calls
        if ($this->config->allowedFunctions !== null) {
            $calledFunctions = $this->extractFunctionCalls($stripped);

            foreach ($calledFunctions as $fn) {
                if (!$this->config->isFunctionAllowed($fn)) {
                    throw TemplateSyntaxException::invalidExpression(
                        $expression,
                        "function '{$fn}' is not in the allowed functions list",
                        $templateFile,
                        $line,
                    );
                }
            }
        }
    }

    /**
     * Return true if the expression is valid under the current config.
     */
    public function isValid(string $expression, string $templateFile = '', int $line = 0): bool
    {
        try {
            $this->validate($expression, $templateFile, $line);

            return true;
        } catch (TemplateSyntaxException) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Replace string literal contents with empty strings to avoid false positives.
     * Handles single-quoted, double-quoted, and heredoc strings at a basic level.
     */
    private function stripStringLiterals(string $expr): string
    {
        // Strip single-quoted strings (handle escaped quotes)
        $result = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $expr) ?? $expr;

        // Strip double-quoted strings (handle escaped quotes and variables)
        $result = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $result) ?? $result;

        return $result;
    }

    /**
     * Check if a function with the given name is called in the expression.
     */
    private function containsFunctionCall(string $expr, string $functionName): bool
    {
        $pattern = '/\b' . preg_quote($functionName, '/') . '\s*\(/i';

        return (bool) preg_match($pattern, $expr);
    }

    /**
     * Extract all function call names from the expression.
     *
     * This regex finds patterns like `funcName(` where funcName is a PHP
     * identifier.  Method calls (`$obj->method(`) are excluded since they
     * are controlled by the object type, not the function whitelist.
     *
     * @return string[]
     */
    private function extractFunctionCalls(string $expr): array
    {
        // Match word boundary + identifier + opening paren
        // Exclude identifiers preceded by '->' (method calls) or '::' (static)
        preg_match_all(
            '/(?<!->)(?<!::)\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            $expr,
            $matches,
        );

        $names = $matches[1] ?? [];

        // Filter out PHP language constructs that look like function calls
        $constructs = ['if', 'elseif', 'while', 'for', 'foreach', 'switch',
            'match', 'function', 'fn', 'array', 'list', 'echo', 'print',
            'isset', 'unset', 'empty', 'list'];

        return array_values(array_diff($names, $constructs));
    }
}
