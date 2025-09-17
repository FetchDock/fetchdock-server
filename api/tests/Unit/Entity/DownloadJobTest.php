<?php

namespace App\Tests\Unit\Entity;

use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Enum\DownloadStateEnum;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class DownloadJobTest extends TestCase
{
    private DownloadJob $downloadJob;

    protected function setUp(): void
    {
        $this->downloadJob = new DownloadJob();
    }

    public function testEntityInstantiation(): void
    {
        $this->assertNull($this->downloadJob->getId());
        $this->assertNull($this->downloadJob->getUri());
        $this->assertNull($this->downloadJob->getUserAgent());
        $this->assertNull($this->downloadJob->getCookies());
        $this->assertNull($this->downloadJob->getState());
        $this->assertNull($this->downloadJob->getDownloader());
        $this->assertCount(0, $this->downloadJob->getDownloadJobEvents());
    }

    public function testSetAndGetUri(): void
    {
        $uri = 'https://example.com/video.mp4';
        $result = $this->downloadJob->setUri($uri);

        $this->assertSame($this->downloadJob, $result);
        $this->assertSame($uri, $this->downloadJob->getUri());
    }

    public function testSetAndGetUserAgent(): void
    {
        $userAgent = 'MyDownloader/1.0';
        $result = $this->downloadJob->setUserAgent($userAgent);

        $this->assertSame($this->downloadJob, $result);
        $this->assertSame($userAgent, $this->downloadJob->getUserAgent());
    }

    public function testSetUserAgentToNull(): void
    {
        $this->downloadJob->setUserAgent('test');
        $result = $this->downloadJob->setUserAgent(null);

        $this->assertSame($this->downloadJob, $result);
        $this->assertNull($this->downloadJob->getUserAgent());
    }

    public function testSetAndGetCookies(): void
    {
        $cookies = ['session' => 'abc123', 'token' => 'xyz456'];
        $result = $this->downloadJob->setCookies($cookies);

        $this->assertSame($this->downloadJob, $result);
        $this->assertSame($cookies, $this->downloadJob->getCookies());
    }

    public function testSetCookiesToNull(): void
    {
        $this->downloadJob->setCookies(['test' => 'value']);
        $result = $this->downloadJob->setCookies(null);

        $this->assertSame($this->downloadJob, $result);
        $this->assertNull($this->downloadJob->getCookies());
    }

    public function testSetAndGetState(): void
    {
        $state = DownloadStateEnum::PENDING;
        $result = $this->downloadJob->setState($state);

        $this->assertSame($this->downloadJob, $result);
        $this->assertSame($state, $this->downloadJob->getState());
    }

    public function testStateTransitions(): void
    {
        // Test all state transitions
        foreach (DownloadStateEnum::cases() as $state) {
            $this->downloadJob->setState($state);
            $this->assertSame($state, $this->downloadJob->getState());
        }
    }

    public function testSetAndGetDownloader(): void
    {
        $downloader = 'yt-dlp-cli';
        $result = $this->downloadJob->setDownloader($downloader);

        $this->assertSame($this->downloadJob, $result);
        $this->assertSame($downloader, $this->downloadJob->getDownloader());
    }

    public function testGetUrl(): void
    {
        $uri = 'https://example.com/video.mp4';
        $this->downloadJob->setUri($uri);

        $url = $this->downloadJob->getUrl();

        $this->assertInstanceOf(Uri::class, $url);
        $this->assertSame($uri, (string)$url);
    }

    public function testAddDownloadJobEvent(): void
    {
        $event = new DownloadJobEvent();
        $result = $this->downloadJob->addDownloadJobEvent($event);

        $this->assertSame($this->downloadJob, $result);
        $this->assertCount(1, $this->downloadJob->getDownloadJobEvents());
        $this->assertTrue($this->downloadJob->getDownloadJobEvents()->contains($event));
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
    }

    public function testAddSameDownloadJobEventTwice(): void
    {
        $event = new DownloadJobEvent();
        $this->downloadJob->addDownloadJobEvent($event);
        $this->downloadJob->addDownloadJobEvent($event);

        $this->assertCount(1, $this->downloadJob->getDownloadJobEvents());
    }

    public function testRemoveDownloadJobEvent(): void
    {
        $event = new DownloadJobEvent();
        $this->downloadJob->addDownloadJobEvent($event);
        
        $result = $this->downloadJob->removeDownloadJobEvent($event);

        $this->assertSame($this->downloadJob, $result);
        $this->assertCount(0, $this->downloadJob->getDownloadJobEvents());
        $this->assertNull($event->getDownloadJob());
    }

    public function testRemoveNonExistentDownloadJobEvent(): void
    {
        $event = new DownloadJobEvent();
        $result = $this->downloadJob->removeDownloadJobEvent($event);

        $this->assertSame($this->downloadJob, $result);
        $this->assertCount(0, $this->downloadJob->getDownloadJobEvents());
    }

    public function testRemoveDownloadJobEventThatBelongsToAnotherJob(): void
    {
        $event = new DownloadJobEvent();
        $anotherJob = new DownloadJob();
        $anotherJob->addDownloadJobEvent($event);
        
        $result = $this->downloadJob->removeDownloadJobEvent($event);

        $this->assertSame($this->downloadJob, $result);
        $this->assertCount(0, $this->downloadJob->getDownloadJobEvents());
        // Event should still belong to the other job
        $this->assertSame($anotherJob, $event->getDownloadJob());
    }

    public function testComplexScenario(): void
    {
        // Test a complex scenario with multiple operations
        $this->downloadJob
            ->setUri('https://youtube.com/watch?v=test')
            ->setUserAgent('Browser/1.0')
            ->setCookies(['auth' => 'token123'])
            ->setState(DownloadStateEnum::IN_PROGRESS)
            ->setDownloader('yt-dlp-cli');

        $event1 = new DownloadJobEvent();
        $event2 = new DownloadJobEvent();
        
        $this->downloadJob
            ->addDownloadJobEvent($event1)
            ->addDownloadJobEvent($event2);

        // Verify all properties are set correctly
        $this->assertSame('https://youtube.com/watch?v=test', $this->downloadJob->getUri());
        $this->assertSame('Browser/1.0', $this->downloadJob->getUserAgent());
        $this->assertSame(['auth' => 'token123'], $this->downloadJob->getCookies());
        $this->assertSame(DownloadStateEnum::IN_PROGRESS, $this->downloadJob->getState());
        $this->assertSame('yt-dlp-cli', $this->downloadJob->getDownloader());
        $this->assertCount(2, $this->downloadJob->getDownloadJobEvents());

        // Test URL conversion
        $url = $this->downloadJob->getUrl();
        $this->assertSame('youtube.com', $url->getHost());
        $this->assertSame('/watch', $url->getPath());
        $this->assertSame('v=test', $url->getQuery());
    }
}