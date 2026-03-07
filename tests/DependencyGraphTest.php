<?php

declare(strict_types=1);

namespace Wik\Lexer\Tests;

use PHPUnit\Framework\TestCase;
use Wik\Lexer\Cache\DependencyGraph;

final class DependencyGraphTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/lexer_depgraph_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->cacheDir);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeTempFile(): string
    {
        $path = $this->cacheDir . '/dep_' . uniqid() . '.lex';
        file_put_contents($path, 'x');
        return $path;
    }

    private function graph(): DependencyGraph
    {
        return new DependencyGraph($this->cacheDir);
    }

    // -----------------------------------------------------------------------
    // record() / getDeps()
    // -----------------------------------------------------------------------

    public function testRecordAndGetDeps(): void
    {
        $dep1 = $this->makeTempFile();
        $dep2 = $this->makeTempFile();

        $graph = $this->graph();
        $graph->record('/tpl/home.lex', [$dep1 => 1000, $dep2 => 2000]);

        $this->assertSame([$dep1 => 1000, $dep2 => 2000], $graph->getDeps('/tpl/home.lex'));
    }

    public function testGetDepsReturnsEmptyForUnknownTemplate(): void
    {
        $this->assertSame([], $this->graph()->getDeps('/tpl/unknown.lex'));
    }

    public function testRecordReplacesExistingEntry(): void
    {
        $dep1 = $this->makeTempFile();
        $dep2 = $this->makeTempFile();

        $graph = $this->graph();
        $graph->record('/tpl/home.lex', [$dep1 => 1000]);
        $graph->record('/tpl/home.lex', [$dep2 => 9999]);

        $this->assertSame([$dep2 => 9999], $graph->getDeps('/tpl/home.lex'));
    }

    // -----------------------------------------------------------------------
    // Persistence: new instance reads data written by previous instance
    // -----------------------------------------------------------------------

    public function testDataPersistsToDisk(): void
    {
        $dep = $this->makeTempFile();

        $this->graph()->record('/tpl/home.lex', [$dep => 1234]);

        // New instance — must reload from view_dependencies.json
        $fresh = $this->graph();
        $this->assertSame([$dep => 1234], $fresh->getDeps('/tpl/home.lex'));
    }

    public function testViewDependenciesJsonIsCreated(): void
    {
        $dep = $this->makeTempFile();
        $this->graph()->record('/tpl/page.lex', [$dep => filemtime($dep)]);

        $this->assertFileExists($this->cacheDir . '/view_dependencies.json');
    }

    public function testJsonIsValidAndReadable(): void
    {
        $dep = $this->makeTempFile();
        $mtime = filemtime($dep);
        $this->graph()->record('/tpl/page.lex', [$dep => $mtime]);

        $raw  = file_get_contents($this->cacheDir . '/view_dependencies.json');
        $data = json_decode($raw, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('/tpl/page.lex', $data);
        $this->assertSame($mtime, $data['/tpl/page.lex'][$dep]);
    }

    // -----------------------------------------------------------------------
    // isStale()
    // -----------------------------------------------------------------------

    public function testIsStaleReturnsFalseWhenMtimesMatch(): void
    {
        $dep   = $this->makeTempFile();
        $mtime = filemtime($dep);

        $graph = $this->graph();
        $graph->record('/tpl/home.lex', [$dep => $mtime]);

        $this->assertFalse($graph->isStale('/tpl/home.lex'));
    }

    public function testIsStaleReturnsTrueWhenDepModified(): void
    {
        $dep   = $this->makeTempFile();
        $mtime = filemtime($dep);

        $graph = $this->graph();
        $graph->record('/tpl/home.lex', [$dep => $mtime]);

        // Simulate dep file being modified (bump mtime by 2 seconds)
        touch($dep, $mtime + 2);
        clearstatcache(true, $dep);

        $this->assertTrue($graph->isStale('/tpl/home.lex'));
    }

    public function testIsStaleReturnsFalseForUnrecordedTemplate(): void
    {
        // No deps recorded → not considered stale (nothing to check)
        $this->assertFalse($this->graph()->isStale('/tpl/nevercompiled.lex'));
    }

    public function testIsStaleReturnsFalseWhenDepFileDisappears(): void
    {
        // If the dep file no longer exists, filemtime returns false
        // which means we cannot confirm it changed → not stale
        $dep   = $this->makeTempFile();
        $mtime = filemtime($dep);

        $graph = $this->graph();
        $graph->record('/tpl/home.lex', [$dep => $mtime]);

        unlink($dep);
        clearstatcache(true, $dep);

        $this->assertFalse($graph->isStale('/tpl/home.lex'));
    }

    // -----------------------------------------------------------------------
    // getDependents() — reverse lookup
    // -----------------------------------------------------------------------

    public function testGetDependentsReturnTemplatesThatUseDep(): void
    {
        $layout = $this->makeTempFile();

        $graph = $this->graph();
        $graph->record('/tpl/home.lex',  [$layout => 1000]);
        $graph->record('/tpl/about.lex', [$layout => 1000]);

        $dependents = $graph->getDependents($layout);
        sort($dependents);

        $this->assertSame(['/tpl/about.lex', '/tpl/home.lex'], $dependents);
    }

    public function testGetDependentsReturnsEmptyWhenNoneDepend(): void
    {
        $this->assertSame([], $this->graph()->getDependents('/tpl/nobody_uses_this.lex'));
    }

    // -----------------------------------------------------------------------
    // forget()
    // -----------------------------------------------------------------------

    public function testForgetRemovesTemplateEntry(): void
    {
        $dep   = $this->makeTempFile();
        $graph = $this->graph();
        $graph->record('/tpl/home.lex', [$dep => 1000]);
        $graph->forget('/tpl/home.lex');

        $this->assertSame([], $graph->getDeps('/tpl/home.lex'));
    }

    public function testForgetPersistsToDisk(): void
    {
        $dep = $this->makeTempFile();
        $this->graph()->record('/tpl/home.lex', [$dep => 1000]);

        $graph2 = $this->graph();
        $graph2->forget('/tpl/home.lex');

        // Third instance — must see the forget
        $this->assertSame([], $this->graph()->getDeps('/tpl/home.lex'));
    }

    // -----------------------------------------------------------------------
    // flush()
    // -----------------------------------------------------------------------

    public function testFlushClearsAllEntries(): void
    {
        $dep1 = $this->makeTempFile();
        $dep2 = $this->makeTempFile();

        $graph = $this->graph();
        $graph->record('/tpl/a.lex', [$dep1 => 1]);
        $graph->record('/tpl/b.lex', [$dep2 => 2]);
        $graph->flush();

        $this->assertSame([], $graph->all());
    }

    public function testFlushPersistsToDisk(): void
    {
        $dep = $this->makeTempFile();
        $this->graph()->record('/tpl/home.lex', [$dep => 1000]);

        $this->graph()->flush();

        $this->assertSame([], $this->graph()->all());
    }

    // -----------------------------------------------------------------------
    // all()
    // -----------------------------------------------------------------------

    public function testAllReturnsFullForwardMap(): void
    {
        $dep1 = $this->makeTempFile();
        $dep2 = $this->makeTempFile();

        $graph = $this->graph();
        $graph->record('/tpl/a.lex', [$dep1 => 1]);
        $graph->record('/tpl/b.lex', [$dep2 => 2]);

        $all = $graph->all();

        $this->assertArrayHasKey('/tpl/a.lex', $all);
        $this->assertArrayHasKey('/tpl/b.lex', $all);
        $this->assertSame([$dep1 => 1], $all['/tpl/a.lex']);
        $this->assertSame([$dep2 => 2], $all['/tpl/b.lex']);
    }
}
