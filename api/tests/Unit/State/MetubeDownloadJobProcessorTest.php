<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Operation;
use App\Dto\JobAcceptedDTO;
use App\Enum\JobTypeEnum;
use App\Model\MetubeDownloadJob;
use App\State\DownloadJobQueuedProcessor;
use App\State\MetubeDownloadJobProcessor;
use PHPUnit\Framework\TestCase;

class MetubeDownloadJobProcessorTest extends TestCase
{
    private MetubeDownloadJobProcessor $processor;
    private DownloadJobQueuedProcessor $downloadJobQueuedProcessor;
    private Operation $operation;

    protected function setUp(): void
    {
        $this->downloadJobQueuedProcessor = $this->createMock(DownloadJobQueuedProcessor::class);
        $this->operation = $this->createMock(Operation::class);
        
        $this->processor = new MetubeDownloadJobProcessor($this->downloadJobQueuedProcessor);
    }

    public function testProcessConvertsMetubeJobToDownloadJobDTO(): void
    {
        $metubeJob = new MetubeDownloadJob();
        $metubeJob->url = 'https://youtube.com/watch?v=test123';

        $expectedResult = new JobAcceptedDTO();
        $expectedResult->setJobId(456);
        $expectedResult->setJobType(JobTypeEnum::DOWNLOAD);

        $this->downloadJobQueuedProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->callback(function ($dto) {
                    return $dto->uri === 'https://youtube.com/watch?v=test123';
                }),
                $this->operation,
                [],
                []
            )
            ->willReturn($expectedResult);

        $result = $this->processor->process($metubeJob, $this->operation);

        $this->assertInstanceOf(JobAcceptedDTO::class, $result);
        $this->assertSame(456, $result->getJobId());
        $this->assertSame(JobTypeEnum::DOWNLOAD, $result->getJobType());
    }

    public function testProcessWithUriVariablesAndContext(): void
    {
        $metubeJob = new MetubeDownloadJob();
        $metubeJob->url = 'https://vimeo.com/123456789';

        $uriVariables = ['id' => 123];
        $context = ['operation' => 'create'];
        
        $expectedResult = new JobAcceptedDTO();
        $expectedResult->setJobId(789);
        $expectedResult->setJobType(JobTypeEnum::DOWNLOAD);

        $this->downloadJobQueuedProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->callback(function ($dto) {
                    return $dto->uri === 'https://vimeo.com/123456789';
                }),
                $this->operation,
                $uriVariables,
                $context
            )
            ->willReturn($expectedResult);

        $result = $this->processor->process($metubeJob, $this->operation, $uriVariables, $context);

        $this->assertSame($expectedResult, $result);
    }

    public function testProcessWithDifferentUrls(): void
    {
        $testUrls = [
            'https://youtube.com/watch?v=abc123',
            'https://twitter.com/user/status/123456',
            'https://instagram.com/p/abcdef/',
            'http://example.com/video.mp4',
        ];

        foreach ($testUrls as $index => $url) {
            $metubeJob = new MetubeDownloadJob();
            $metubeJob->url = $url;

            $expectedJobId = 100 + $index;
            $expectedResult = new JobAcceptedDTO();
            $expectedResult->setJobId($expectedJobId);
            $expectedResult->setJobType(JobTypeEnum::DOWNLOAD);

            $processor = new MetubeDownloadJobProcessor(
                $this->createMock(DownloadJobQueuedProcessor::class)
            );

            $mockProcessor = $this->createMock(DownloadJobQueuedProcessor::class);
            $mockProcessor->expects($this->once())
                ->method('process')
                ->with($this->callback(function ($dto) use ($url) {
                    return $dto->uri === $url;
                }))
                ->willReturn($expectedResult);

            $processor = new MetubeDownloadJobProcessor($mockProcessor);
            $result = $processor->process($metubeJob, $this->operation);

            $this->assertSame($expectedJobId, $result->getJobId());
        }
    }

    public function testProcessPassesThroughExceptions(): void
    {
        $metubeJob = new MetubeDownloadJob();
        $metubeJob->url = 'https://invalid-url';

        $this->downloadJobQueuedProcessor->expects($this->once())
            ->method('process')
            ->willThrowException(new \InvalidArgumentException('Invalid URL'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL');

        $this->processor->process($metubeJob, $this->operation);
    }

    public function testProcessCreatesCorrectDownloadJobDTO(): void
    {
        $metubeJob = new MetubeDownloadJob();
        $metubeJob->url = 'https://example.com/test.mp4';

        $capturedDTO = null;
        $this->downloadJobQueuedProcessor->expects($this->once())
            ->method('process')
            ->willReturnCallback(function ($dto) use (&$capturedDTO) {
                $capturedDTO = $dto;
                
                $result = new JobAcceptedDTO();
                $result->setJobId(555);
                $result->setJobType(JobTypeEnum::DOWNLOAD);
                return $result;
            });

        $this->processor->process($metubeJob, $this->operation);

        // Verify the DTO was created correctly
        $this->assertNotNull($capturedDTO);
        $this->assertSame('https://example.com/test.mp4', $capturedDTO->uri);
        
        // Verify that other DTO properties are not set (default behavior)
        $this->assertFalse(isset($capturedDTO->userAgent));
        $this->assertFalse(isset($capturedDTO->cookies));
        $this->assertFalse(isset($capturedDTO->downloader));
    }

    public function testProcessMaintainsOperationContext(): void
    {
        $metubeJob = new MetubeDownloadJob();
        $metubeJob->url = 'https://test.com/video';

        $operation = $this->createMock(Operation::class);
        $uriVariables = ['var1' => 'value1'];
        $context = ['ctx1' => 'contextValue'];

        $this->downloadJobQueuedProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->anything(),
                $this->identicalTo($operation),
                $this->identicalTo($uriVariables),
                $this->identicalTo($context)
            )
            ->willReturn(new JobAcceptedDTO());

        $this->processor->process($metubeJob, $operation, $uriVariables, $context);
    }
}