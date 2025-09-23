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

    public function testJobUuidGettersAndSetters(): void
    {
        $uuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $result = $this->dto->setJobUuid($uuid);
        
        $this->assertSame($this->dto, $result);
        $this->assertSame($uuid, $this->dto->getJobUuid());
    }

    public function testTokenGettersAndSetters(): void
    {
        $token = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        $result = $this->dto->setToken($token);
        
        $this->assertSame($this->dto, $result);
        $this->assertSame($token, $this->dto->getToken());
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
        $uuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $token = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        
        $result = $this->dto
            ->setJobUuid($uuid)
            ->setToken($token)
            ->setJobType(JobTypeEnum::DOWNLOAD);
        
        $this->assertSame($this->dto, $result);
        $this->assertSame($uuid, $this->dto->getJobUuid());
        $this->assertSame($token, $this->dto->getToken());
        $this->assertSame(JobTypeEnum::DOWNLOAD, $this->dto->getJobType());
    }

    public function testSetJobUuidWithMinimalValue(): void
    {
        $uuid = '00000000-0000-0000-0000-000000000000';
        $this->dto->setJobUuid($uuid);
        $this->assertSame($uuid, $this->dto->getJobUuid());
    }

    public function testSetTokenWithShortValue(): void
    {
        $this->dto->setToken('abc123');
        $this->assertSame('abc123', $this->dto->getToken());
    }

    public function testSetTokenWithLongValue(): void
    {
        $longToken = str_repeat('a', 64);
        $this->dto->setToken($longToken);
        $this->assertSame($longToken, $this->dto->getToken());
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
        $uuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $token = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        
        // Simulate typical usage
        $dto->setJobUuid($uuid)
            ->setToken($token)
            ->setJobType(JobTypeEnum::DOWNLOAD);
        
        // Verify final state
        $this->assertSame($uuid, $dto->getJobUuid());
        $this->assertSame($token, $dto->getToken());
        $this->assertSame(JobTypeEnum::DOWNLOAD, $dto->getJobType());
        $this->assertSame('Download', $dto->getJobType()->label());
    }
}