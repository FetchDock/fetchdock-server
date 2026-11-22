<?php

namespace App\Tests\Unit\Entity;

use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use PHPUnit\Framework\TestCase;

class DownloadedFileTest extends TestCase
{
    public function testPathJoinsTheDirectoryAndTheRelativePath(): void
    {
        $file = new DownloadedFile()->setLocation(new DownloadDirectory('Default', '/downloads/'), 'youtube/video.mp4');

        $this->assertSame('/downloads/youtube/video.mp4', $file->getPath());
        $this->assertSame('video.mp4', $file->getFilename());
    }

    public function testLegacyAbsolutePathIsKept(): void
    {
        $file = new DownloadedFile()->setLocation(new DownloadDirectory('Default', '/downloads'), '/legacy/video.mp4');

        $this->assertSame('/legacy/video.mp4', $file->getPath());
    }

    public function testPathIsNullWithoutLocation(): void
    {
        $this->assertNull(new DownloadedFile()->getPath());
    }
}
