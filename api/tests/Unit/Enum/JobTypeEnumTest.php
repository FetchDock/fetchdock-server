<?php

namespace App\Tests\Unit\Enum;

use App\Enum\JobTypeEnum;
use PHPUnit\Framework\TestCase;

class JobTypeEnumTest extends TestCase
{
    public function testAllEnumCases(): void
    {
        $expectedCases = ['DOWNLOAD'];
        $cases = JobTypeEnum::cases();
        
        $this->assertCount(1, $cases);
        
        foreach ($cases as $case) {
            $this->assertContains($case->name, $expectedCases);
        }
    }

    public function testEnumNames(): void
    {
        $this->assertSame('DOWNLOAD', JobTypeEnum::DOWNLOAD->name);
    }

    public function testDownloadLabel(): void
    {
        $this->assertSame('Download', JobTypeEnum::DOWNLOAD->label());
    }

    public function testLabelMethodExists(): void
    {
        foreach (JobTypeEnum::cases() as $case) {
            $this->assertIsString($case->label());
            $this->assertNotEmpty($case->label());
        }
    }

    public function testEnumComparison(): void
    {
        $download1 = JobTypeEnum::DOWNLOAD;
        $download2 = JobTypeEnum::DOWNLOAD;

        $this->assertTrue($download1 === $download2);
    }

    public function testMatchExpressionReturnsCorrectLabel(): void
    {
        $label = match (JobTypeEnum::DOWNLOAD) {
            JobTypeEnum::DOWNLOAD => 'Download Job Type',
        };

        $this->assertSame('Download Job Type', $label);
    }
}