<?php

namespace App\Tests\Unit\Service\FileMove;

use App\Service\FileMove\FileMoveFailure;
use App\Service\FileMove\FileMover;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileMoverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/file-mover-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/a/site', 0o777, true);
        mkdir($this->root.'/b', 0o777, true);
        file_put_contents($this->root.'/a/site/video.mp4', 'video');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public static function modes(): iterable
    {
        yield 'same filesystem' => [false, FileMover::LINKED];
        yield 'across filesystems' => [true, FileMover::COPIED];
    }

    #[DataProvider('modes')]
    public function testTransfersKeepingTheSourceUntilFinish(bool $alwaysCopy, string $mode): void
    {
        $mover = new FileMover($alwaysCopy);

        $this->assertSame($mode, $mover->transfer($this->source(), $this->target(), verifyChecksum: true));

        $this->assertSame('video', file_get_contents($this->target()), 'Missing directories are created');
        $this->assertFileExists($this->source(), 'The source stays until the new location is committed');
        $this->assertFileDoesNotExist($this->target().FileMover::PART_SUFFIX);

        $mover->finish($this->source());
        $this->assertFileDoesNotExist($this->source());
        $this->assertSame('video', file_get_contents($this->target()));
    }

    #[DataProvider('modes')]
    public function testUndoKeepsTheSource(bool $alwaysCopy, string $mode): void
    {
        $mover = new FileMover($alwaysCopy);
        $this->assertSame($mode, $mover->transfer($this->source(), $this->target(), false));

        $mover->undo($this->source(), $this->target());

        $this->assertFileExists($this->source());
        $this->assertFileDoesNotExist($this->target());
    }

    public function testResumesALinkMadeBeforeAnInterruption(): void
    {
        mkdir(\dirname($this->target()), 0o777, true);
        link($this->source(), $this->target());

        $this->assertSame(FileMover::LINKED, new FileMover()->transfer($this->source(), $this->target(), false));
    }

    public function testNeverOverwritesTheTarget(): void
    {
        mkdir(\dirname($this->target()), 0o777, true);
        file_put_contents($this->target(), 'other content');

        try {
            new FileMover(true)->transfer($this->source(), $this->target(), false);
            $this->fail('A conflict was expected');
        } catch (FileMoveFailure $e) {
            $this->assertFalse($e->skip);
            $this->assertStringContainsString('conflict', $e->getMessage());
        }
        $this->assertSame('other content', file_get_contents($this->target()));
        $this->assertFileExists($this->source());
    }

    public function testSkipsAFileAlreadyAtTheTarget(): void
    {
        mkdir(\dirname($this->target()), 0o777, true);
        file_put_contents($this->target(), 'video');

        try {
            new FileMover(true)->transfer($this->source(), $this->target(), false);
            $this->fail('A skip was expected');
        } catch (FileMoveFailure $e) {
            $this->assertTrue($e->skip);
            $this->assertSame('already at target', $e->getMessage());
        }
    }

    public function testFailsOnAMissingSource(): void
    {
        unlink($this->source());

        $this->expectException(FileMoveFailure::class);
        $this->expectExceptionMessage('missing source');

        new FileMover()->transfer($this->source(), $this->target(), false);
    }

    public function testReplacesAPartFileLeftByAnInterruptedCopy(): void
    {
        mkdir(\dirname($this->target()), 0o777, true);
        file_put_contents($this->target().FileMover::PART_SUFFIX, 'partial');

        new FileMover(true)->transfer($this->source(), $this->target(), true);

        $this->assertSame('video', file_get_contents($this->target()));
        $this->assertFileDoesNotExist($this->target().FileMover::PART_SUFFIX);
    }

    private function source(): string
    {
        return $this->root.'/a/site/video.mp4';
    }

    private function target(): string
    {
        return $this->root.'/b/site/video.mp4';
    }
}
