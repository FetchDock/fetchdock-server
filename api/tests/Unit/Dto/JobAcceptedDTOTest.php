<?php

namespace App\Tests\Unit\Dto;

use App\Dto\JobAcceptedDTO;
use App\Enum\JobTypeEnum;
use PHPUnit\Framework\TestCase;

class JobAcceptedDTOTest extends TestCase
{
    private JobAcceptedDTO $dto;

    protected function setUp(): void
    {
        $this->dto = new JobAcceptedDTO();
    }

    public function testJobIdGettersAndSetters(): void
    {
        $result = $this->dto->setJobId(123);
        
        $this->assertSame($this->dto, $result);
        $this->assertSame(123, $this->dto->getJobId());
    }

    public function testJobTypeGettersAndSetters(): void
    {
        $result = $this->dto->setJobType(JobTypeEnum::DOWNLOAD);
        
        $this->assertSame($this->dto, $result);
        $this->assertSame(JobTypeEnum::DOWNLOAD, $this->dto->getJobType());
    }

    public function testStatusGettersAndSetters(): void
    {
        $this->assertSame('Accepted', $this->dto->getStatus());
        
        $result = $this->dto->setStatus('Processing');
        
        $this->assertSame($this->dto, $result);
        $this->assertSame('Processing', $this->dto->getStatus());
    }

    public function testMessageGettersAndSetters(): void
    {
        $this->assertSame('Your job has been accepted and is being processed.', $this->dto->getMessage());
        
        $result = $this->dto->setMessage('Custom message');
        
        $this->assertSame($this->dto, $result);
        $this->assertSame('Custom message', $this->dto->getMessage());
    }

    public function testFluentInterface(): void
    {
        $result = $this->dto
            ->setJobId(456)
            ->setJobType(JobTypeEnum::DOWNLOAD);
        
        $this->assertSame($this->dto, $result);
        $this->assertSame(456, $this->dto->getJobId());
        $this->assertSame(JobTypeEnum::DOWNLOAD, $this->dto->getJobType());
    }

    public function testSetJobIdWithZero(): void
    {
        $this->dto->setJobId(0);
        $this->assertSame(0, $this->dto->getJobId());
    }

    public function testSetJobIdWithNegativeValue(): void
    {
        $this->dto->setJobId(-1);
        $this->assertSame(-1, $this->dto->getJobId());
    }

    public function testSetJobIdWithLargeValue(): void
    {
        $largeId = PHP_INT_MAX;
        $this->dto->setJobId($largeId);
        $this->assertSame($largeId, $this->dto->getJobId());
    }

    public function testInitialState(): void
    {
        $dto = new JobAcceptedDTO();
        
        $this->assertSame('Accepted', $dto->getStatus());
        $this->assertSame('Your job has been accepted and is being processed.', $dto->getMessage());
    }

    public function testCompleteWorkflow(): void
    {
        $dto = new JobAcceptedDTO();
        
        // Simulate typical usage
        $dto->setJobId(789)
            ->setJobType(JobTypeEnum::DOWNLOAD);
        
        // Verify final state
        $this->assertSame(789, $dto->getJobId());
        $this->assertSame(JobTypeEnum::DOWNLOAD, $dto->getJobType());
        $this->assertSame('Download', $dto->getJobType()->label());
    }
}