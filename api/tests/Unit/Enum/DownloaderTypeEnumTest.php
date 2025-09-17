<?php

namespace App\Tests\Unit\Enum;

use App\Enum\DownloaderTypeEnum;
use PHPUnit\Framework\TestCase;

class DownloaderTypeEnumTest extends TestCase
{
    public function testAllEnumCases(): void
    {
        $expectedCases = ['WEB_DOWNLOADER', 'CLI_DOWNLOADER'];
        $cases = DownloaderTypeEnum::cases();
        
        $this->assertCount(2, $cases);
        
        foreach ($cases as $case) {
            $this->assertContains($case->name, $expectedCases);
        }
    }

    public function testEnumNames(): void
    {
        $this->assertSame('WEB_DOWNLOADER', DownloaderTypeEnum::WEB_DOWNLOADER->name);
        $this->assertSame('CLI_DOWNLOADER', DownloaderTypeEnum::CLI_DOWNLOADER->name);
    }

    public function testEnumLabels(): void
    {
        $this->assertSame('Web Downloader', DownloaderTypeEnum::WEB_DOWNLOADER->label());
        $this->assertSame('CLI Downloader', DownloaderTypeEnum::CLI_DOWNLOADER->label());
    }

    public function testEnumComparison(): void
    {
        $web1 = DownloaderTypeEnum::WEB_DOWNLOADER;
        $web2 = DownloaderTypeEnum::WEB_DOWNLOADER;
        $cli = DownloaderTypeEnum::CLI_DOWNLOADER;

        $this->assertTrue($web1 === $web2);
        $this->assertFalse($web1 === $cli);
        $this->assertTrue($web1 !== $cli);
    }

    public function testLabelMethodExists(): void
    {
        foreach (DownloaderTypeEnum::cases() as $case) {
            $this->assertIsString($case->label());
            $this->assertNotEmpty($case->label());
        }
    }

    /**
     * Test that each enum case has an appropriate label
     */
    public function testLabelAccuracy(): void
    {
        $this->assertStringContainsString('Web', DownloaderTypeEnum::WEB_DOWNLOADER->label());
        $this->assertStringContainsString('CLI', DownloaderTypeEnum::CLI_DOWNLOADER->label());
        $this->assertStringContainsString('Downloader', DownloaderTypeEnum::WEB_DOWNLOADER->label());
        $this->assertStringContainsString('Downloader', DownloaderTypeEnum::CLI_DOWNLOADER->label());
    }
}