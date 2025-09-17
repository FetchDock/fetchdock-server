<?php

namespace App\Tests\Unit\Enum;

use App\Enum\DownloadStateEnum;
use PHPUnit\Framework\TestCase;

class DownloadStateEnumTest extends TestCase
{
    public function testAllEnumCases(): void
    {
        $expectedCases = [
            'PENDING' => 0,
            'IN_PROGRESS' => 1,
            'COMPLETED' => 2,
            'FAILED' => 3,
            'CANCELED' => 4,
        ];

        $cases = DownloadStateEnum::cases();
        $this->assertCount(5, $cases);

        foreach ($cases as $case) {
            $this->assertArrayHasKey($case->name, $expectedCases);
            $this->assertSame($expectedCases[$case->name], $case->value);
        }
    }

    public function testEnumValues(): void
    {
        $this->assertSame(0, DownloadStateEnum::PENDING->value);
        $this->assertSame(1, DownloadStateEnum::IN_PROGRESS->value);
        $this->assertSame(2, DownloadStateEnum::COMPLETED->value);
        $this->assertSame(3, DownloadStateEnum::FAILED->value);
        $this->assertSame(4, DownloadStateEnum::CANCELED->value);
    }

    public function testEnumNames(): void
    {
        $this->assertSame('PENDING', DownloadStateEnum::PENDING->name);
        $this->assertSame('IN_PROGRESS', DownloadStateEnum::IN_PROGRESS->name);
        $this->assertSame('COMPLETED', DownloadStateEnum::COMPLETED->name);
        $this->assertSame('FAILED', DownloadStateEnum::FAILED->name);
        $this->assertSame('CANCELED', DownloadStateEnum::CANCELED->name);
    }

    public function testFromValue(): void
    {
        $this->assertSame(DownloadStateEnum::PENDING, DownloadStateEnum::from(0));
        $this->assertSame(DownloadStateEnum::IN_PROGRESS, DownloadStateEnum::from(1));
        $this->assertSame(DownloadStateEnum::COMPLETED, DownloadStateEnum::from(2));
        $this->assertSame(DownloadStateEnum::FAILED, DownloadStateEnum::from(3));
        $this->assertSame(DownloadStateEnum::CANCELED, DownloadStateEnum::from(4));
    }

    public function testTryFromValue(): void
    {
        $this->assertSame(DownloadStateEnum::PENDING, DownloadStateEnum::tryFrom(0));
        $this->assertSame(DownloadStateEnum::IN_PROGRESS, DownloadStateEnum::tryFrom(1));
        $this->assertSame(DownloadStateEnum::COMPLETED, DownloadStateEnum::tryFrom(2));
        $this->assertSame(DownloadStateEnum::FAILED, DownloadStateEnum::tryFrom(3));
        $this->assertSame(DownloadStateEnum::CANCELED, DownloadStateEnum::tryFrom(4));
        $this->assertNull(DownloadStateEnum::tryFrom(999));
    }

    public function testFromInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        DownloadStateEnum::from(999);
    }

    public function testEnumComparison(): void
    {
        $pending1 = DownloadStateEnum::PENDING;
        $pending2 = DownloadStateEnum::PENDING;
        $inProgress = DownloadStateEnum::IN_PROGRESS;

        $this->assertTrue($pending1 === $pending2);
        $this->assertFalse($pending1 === $inProgress);
        $this->assertTrue($pending1 !== $inProgress);
    }

    /**
     * Test state progression logic (conceptual)
     */
    public function testStateProgression(): void
    {
        // Test typical state progression
        $states = [
            DownloadStateEnum::PENDING,
            DownloadStateEnum::IN_PROGRESS,
            DownloadStateEnum::COMPLETED
        ];

        foreach ($states as $index => $state) {
            $this->assertSame($index, $state->value);
        }

        // Test that FAILED and CANCELED are terminal states with higher values
        $this->assertGreaterThan(DownloadStateEnum::IN_PROGRESS->value, DownloadStateEnum::FAILED->value);
        $this->assertGreaterThan(DownloadStateEnum::IN_PROGRESS->value, DownloadStateEnum::CANCELED->value);
    }
}