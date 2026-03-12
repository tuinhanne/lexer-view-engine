<?php

declare(strict_types=1);

namespace Wik\Lexer\Debug;

use Wik\Lexer\Lexer;

/**
 * Decorator around the main Lexer entry point that collects debug metadata
 * and injects a JSON payload into the rendered HTML for the Chrome DevTools extension.
 *
 * Usage (standalone):
 *   $debug = new LexDebugger(Lexer::fromConfig());
 *   echo $debug->render('home', $data);
 *
 * Usage (PSR-15, via DebugMiddleware):
 *   $app->add(new DebugMiddleware());
 *   // LexDebugger must still be constructed to register hooks.
 *
 * Hook wiring:
 *   ComponentManager → onComponentStart / onComponentEnd
 *   SectionManager   → onSectionEnd
 *   FileCache        → onCacheHit / onCacheMiss
 */
final class LexDebugger
{
    /**
     * Stack of component IDs to match onComponentStart → onComponentEnd pairs.
     * A stack (not a single variable) is required because components can nest.
     *
     * @var string[]
     */
    private array $componentIdStack = [];

    /** ID of the most recently completed component, used by the output wrapper. */
    private string $lastComponentId = '';

    public function __construct(private readonly Lexer $lexer)
    {
        // Build the engine now so getEngine()->getCompiler()->getCache() returns
        // the same FileCache instance that will be used during render.
        $this->lexer->getEngine();
        $this->registerHooks();
    }

    /**
     * Render a template and inject the debug payload into the HTML output.
     *
     * @param  array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        DebugPayload::reset();
        DebugPayload::getInstance()->setRootTemplate($name, '');

        try {
            $html = $this->lexer->getEngine()->render($name, $data);
        } catch (\Throwable $e) {
            $this->recordException(DebugPayload::getInstance(), $e);
            throw $e;
        }

        $payload = DebugPayload::getInstance();

        if (!headers_sent()) {
            header('X-Lex-Debug: 1');
            header('X-Lex-Render-Time: ' . $payload->getRenderTime());
            header('X-Lex-Cache-Hits: ' . $payload->getCacheHitCount());
            header('X-Lex-Cache-Miss: ' . $payload->getCacheMissCount());
            header('X-Lex-Template: ' . $name);
        }

        return $this->injectPayload($html, $payload);
    }

    // -----------------------------------------------------------------------
    // Hook registration
    // -----------------------------------------------------------------------

    private function registerHooks(): void
    {
        $cm    = $this->lexer->getComponentManager();
        $sm    = $this->lexer->getSectionManager();
        $cache = $this->lexer->getEngine()->getCompiler()->getCache();

        // ── Components ───────────────────────────────────────────────────────

        $cm->addHook('onComponentStart', function (string $name, string $file, array $props): void {
            $id = DebugPayload::getInstance()->recordComponentStart($name, $file, $props);
            $this->componentIdStack[] = $id;
        });

        $cm->addHook('onComponentEnd', function (string $_name, string $_file, float $renderMs): void {
            $id = array_pop($this->componentIdStack);
            if ($id !== null) {
                $this->lastComponentId = $id;
                DebugPayload::getInstance()->recordComponentEnd($id, $renderMs);
            }
        });

        $cm->setOutputWrapper(function (string $name, string $file, float $renderMs, string $output): string {
            $id = $this->lastComponentId;
            return sprintf(
                '<span data-lex-component="%s" data-lex-id="%s" data-lex-file="%s" data-lex-render-time="%.2f" style="display:contents">%s</span>',
                htmlspecialchars($name, ENT_QUOTES),
                htmlspecialchars($id, ENT_QUOTES),
                htmlspecialchars($file, ENT_QUOTES),
                $renderMs,
                $output,
            );
        });

        // ── Sections ─────────────────────────────────────────────────────────

        $sm->addHook('onSectionEnd', function (string $name, string $content, string $file): void {
            DebugPayload::getInstance()->recordSection($name, $content, $file);
        });

        $sm->addHook('onSectionYield', function (string $name, string $file): void {
            DebugPayload::getInstance()->recordSectionYield($name, $file);
        });

        // ── Cache ─────────────────────────────────────────────────────────────

        $cache->addHook('onCacheHit', function (string $_key, string $compiledPath): void {
            DebugPayload::getInstance()->recordCacheHit($compiledPath);
        });

        $cache->addHook('onCacheMiss', function (string $key): void {
            DebugPayload::getInstance()->recordCacheMiss($key);
        });
    }

    // -----------------------------------------------------------------------
    // Payload injection
    // -----------------------------------------------------------------------

    private function injectPayload(string $html, DebugPayload $payload): string
    {
        $script = "\n<script id=\"__lex_debug__\" type=\"application/json\">"
                . $payload->toJson()
                . '</script>';

        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $script . '</body>', $html);
        }

        return $html . $script;
    }

    private function recordException(DebugPayload $payload, \Throwable $e): void
    {
        $file   = $e->getFile();
        $line   = $e->getLine();
        $column = 0;

        if (method_exists($e, 'getTemplateLine'))   { $line   = $e->getTemplateLine();   }
        if (method_exists($e, 'getTemplateColumn')) { $column = $e->getTemplateColumn(); }
        if (method_exists($e, 'getTemplateFile'))   { $file   = $e->getTemplateFile();   }

        $payload->recordError(
            type:    (new \ReflectionClass($e))->getShortName(),
            message: $e->getMessage(),
            file:    $file,
            line:    $line,
            column:  $column,
        );
    }
}
