<?php

namespace App\Tests\Unit\Service\Storage;

use App\Service\Storage\DownloadRoots;
use PHPUnit\Framework\TestCase;

class DownloadRootsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/roots-test-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/allowed/site', 0700, true);
        mkdir($this->root.'/elsewhere', 0700, true);
        symlink($this->root.'/elsewhere', $this->root.'/allowed/escape');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testContainsPathsInsideARoot(): void
    {
        $roots = new DownloadRoots([' '.$this->root.'/allowed/ ', '']);

        $this->assertSame([$this->root.'/allowed'], $roots->all());
        $this->assertTrue($roots->contains($this->root.'/allowed'));
        $this->assertTrue($roots->contains($this->root.'/allowed/site'));
        $this->assertTrue($roots->contains($this->root.'/allowed/not-created-yet'));
        $this->assertFalse($roots->contains($this->root.'/allowed-other'));
        $this->assertFalse($roots->contains($this->root.'/allowed/../elsewhere'));
    }

    public function testSymlinksCannotEscapeARoot(): void
    {
        $this->assertFalse(new DownloadRoots([$this->root.'/allowed'])->contains($this->root.'/allowed/escape'));
    }
}
