<?php

declare(strict_types=1);

namespace Wik\Lexer\Debug;

/**
 * Accumulates debug metadata during a single Lex render cycle.
 *
 * Filled by hooks registered in LexDebugger.
 * Consumed by DebugMiddleware / LexDebugger to inject the JSON payload
 * into the HTML response for the Chrome DevTools extension.
 */
final class DebugPayload
{
    private static ?self $instance = null;

    private float $startTime;
    private ?string $rootTemplate  = null;
    private ?string $rootFile      = null;
    private ?array  $layout        = null;

    /** @var array<string, array<string, mixed>> id → component data */
    private array $components = [];

    /** @var array<array{name:string, preview:string}> */
    private array $sections = [];

    /** @var string[] */
    private array $cacheHits = [];

    /** @var string[] */
    private array $cacheMisses = [];

    /** @var array<array{type:string, message:string, file:string, line:int, column:int}> */
    private array $errors = [];

    private int $componentCounter = 0;

    private function __construct()
    {
        $this->startTime = microtime(true);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = new self();
    }

    // -----------------------------------------------------------------------

    public function setRootTemplate(string $name, string $file): void
    {
        $this->rootTemplate = $name;
        $this->rootFile     = $file;
    }

    public function setLayout(string $name, string $file): void
    {
        $this->layout = ['name' => $name, 'file' => $file];
    }

    /**
     * Record a component render start.
     *
     * @param  array<string, mixed> $props
     * @return string  Unique ID used to correlate the matching recordComponentEnd() call.
     */
    public function recordComponentStart(string $name, string $file, array $props): string
    {
        $id = 'c' . (++$this->componentCounter);

        $this->components[$id] = [
            'id'         => $id,
            'name'       => $name,
            'file'       => $file,
            'props'      => $props,
            'slots'      => [],
            'renderTime' => null,
            'domId'      => 'lex-' . $id,
        ];

        return $id;
    }

    /**
     * @param float $renderMs  Render time already measured by ComponentManager hook.
     */
    public function recordComponentEnd(string $id, float $renderMs): void
    {
        if (!isset($this->components[$id])) {
            return;
        }

        $this->components[$id]['renderTime'] = $renderMs;
    }

    /**
     * @param string $content  Captured section content (stored as a short preview).
     */
    public function recordSection(string $name, string $content, string $definedIn = ''): void
    {
        $this->sections[$name] = [
            'name'      => $name,
            'preview'   => mb_substr($content, 0, 120),
            'definedIn' => $definedIn,
            'yieldedIn' => '',
        ];
    }

    public function recordSectionYield(string $name, string $yieldedIn): void
    {
        if (isset($this->sections[$name])) {
            $this->sections[$name]['yieldedIn'] = $yieldedIn;
        }
    }

    public function recordCacheHit(string $file): void
    {
        $this->cacheHits[] = $file;
    }

    public function recordCacheMiss(string $file): void
    {
        $this->cacheMisses[] = $file;
    }

    public function recordError(string $type, string $message, string $file, int $line, int $column = 0): void
    {
        $this->errors[] = compact('type', 'message', 'file', 'line', 'column');
    }

    // -----------------------------------------------------------------------

    public function getRenderTime(): float
    {
        return round((microtime(true) - $this->startTime) * 1000, 2);
    }

    public function getCacheHitCount(): int
    {
        return count($this->cacheHits);
    }

    public function getCacheMissCount(): int
    {
        return count($this->cacheMisses);
    }

    public function getRootTemplate(): ?string
    {
        return $this->rootTemplate;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function toArray(): array
    {
        return [
            'version'    => '1.0',
            'renderTime' => $this->getRenderTime(),
            'template'   => [
                'name' => $this->rootTemplate,
                'file' => $this->rootFile,
            ],
            'layout'     => $this->layout,
            'components' => array_values($this->components),
            'sections'   => array_values($this->sections),
            'cache'      => [
                'hits'   => $this->cacheHits,
                'misses' => $this->cacheMisses,
            ],
            'errors'     => $this->errors,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
