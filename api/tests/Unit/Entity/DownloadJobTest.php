<?php

namespace App\Tests\Unit\Entity;

use App\Dto\CookieDTO;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Enum\DownloadStateEnum;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

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
        $this->assertInstanceOf(Uuid::class, $this->downloadJob->getUuid());
        $this->assertIsString($this->downloadJob->getToken());
        $this->assertSame(64, strlen($this->downloadJob->getToken())); // 32 bytes = 64 hex chars
        $this->assertNull($this->downloadJob->getUri());
        $this->assertNull($this->downloadJob->getUserAgent());
        $this->assertSame([],$this->downloadJob->getCookies());
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
        //$cookies = ['session' => 'abc123', 'token' => 'xyz456'];
        $cookies = [
            new CookieDTO(),
            new CookieDTO(),
        ];
        $result = $this->downloadJob->setCookies($cookies);

        $this->assertSame($this->downloadJob, $result);
        $this->assertSame($cookies, $this->downloadJob->getCookies());
    }

    public function testSetCookiesToNullReturnsEmptyArray(): void
    {
        $this->downloadJob->setCookies([new CookieDTO()]);
        $result = $this->downloadJob->setCookies(null);

        $this->assertSame($this->downloadJob, $result);
        $this->assertSame([], $this->downloadJob->getCookies());
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
        $this->assertSame($uri, (string) $url);
    }

    public function testUuidIsGeneratedAndUnique(): void
    {
        $job1 = new DownloadJob();
        $job2 = new DownloadJob();

        $this->assertInstanceOf(Uuid::class, $job1->getUuid());
        $this->assertInstanceOf(Uuid::class, $job2->getUuid());
        $this->assertNotEquals($job1->getUuid()->toRfc4122(), $job2->getUuid()->toRfc4122());
    }

    public function testTokenIsGeneratedAndUnique(): void
    {
        $job1 = new DownloadJob();
        $job2 = new DownloadJob();

        $this->assertIsString($job1->getToken());
        $this->assertIsString($job2->getToken());
        $this->assertSame(64, strlen($job1->getToken()));
        $this->assertSame(64, strlen($job2->getToken()));
        $this->assertNotEquals($job1->getToken(), $job2->getToken());
        $this->assertTrue(ctype_xdigit($job1->getToken())); // Is hexadecimal
        $this->assertTrue(ctype_xdigit($job2->getToken())); // Is hexadecimal
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
        $cookie = new CookieDTO();
        $cookie->name = 'auth';
        $cookie->value = 'token123';
        // Test a complex scenario with multiple operations
        $this->downloadJob
            ->setUri('https://youtube.com/watch?v=test')
            ->setUserAgent('Browser/1.0')
            ->setCookies([$cookie])
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
        $this->assertSame([$cookie], $this->downloadJob->getCookies());
        $this->assertSame(DownloadStateEnum::IN_PROGRESS, $this->downloadJob->getState());
        $this->assertSame('yt-dlp-cli', $this->downloadJob->getDownloader());
        $this->assertCount(2, $this->downloadJob->getDownloadJobEvents());

        // Test URL conversion
        $url = $this->downloadJob->getUrl();
        $this->assertSame('youtube.com', $url->getHost());
        $this->assertSame('/watch', $url->getPath());
        $this->assertSame('v=test', $url->getQuery());
    }

    public function testAddCookie(): void
    {
        $cookie = new CookieDTO();
        $cookie->name = 'auth';
        $cookie->value = 'token123';
        $this->downloadJob->addCookie($cookie);
        $this->assertIsArray($this->downloadJob->getCookies());
        $this->assertCount(1, $this->downloadJob->getCookies());
        $this->assertContains($cookie, $this->downloadJob->getCookies());
    }

    public function testAddCookieWithSameName(): void
    {
        $cookie1 = new CookieDTO();
        $cookie1->name = 'auth';
        $cookie1->value = 'token123';
        $cookie2 = new CookieDTO();
        $cookie2->name = 'auth';
        $cookie2->value = 'token456';
        $this->downloadJob->addCookie($cookie1);
        $this->downloadJob->addCookie($cookie2);

        $this->assertIsArray($this->downloadJob->getCookies());
        $this->assertCount(2, $this->downloadJob->getCookies());
        $this->assertContains($cookie1, $this->downloadJob->getCookies());
        $this->assertContains($cookie2, $this->downloadJob->getCookies());
    }

    public function testRemoveCookie(): void
    {
        $cookie = new CookieDTO();
        $cookie->name = 'auth';
        $cookie->value = 'token123';
        $this->downloadJob->addCookie($cookie);
        $this->assertIsArray($this->downloadJob->getCookies());
        $this->assertContains($cookie, $this->downloadJob->getCookies());
        $this->downloadJob->removeCookie($cookie);
        $this->assertIsArray($this->downloadJob->getCookies());
        $this->assertNotContains($cookie, $this->downloadJob->getCookies());
        $this->assertCount(0, $this->downloadJob->getCookies());
    }

    public function testSetCookiesWithValuesOtherThanCookieDTOThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->downloadJob->setCookies(['invalid_cookie']);
    }

    public function testSetCookiesWithNullThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->downloadJob->setCookies(
            [
                new CookieDTO(),
                "string"
            ]
        );
    }

    public function testAddFile(): void
    {
        $file = new DownloadedFile();
        $this->downloadJob->addFile($file);
        $this->assertIsArray($this->downloadJob->getFiles()->toArray());
        $this->assertCount(1, $this->downloadJob->getFiles()->toArray());
        $this->assertContains($file, $this->downloadJob->getFiles()->toArray());
    }

    public function testRemoveFile(): void
    {
        $file = new DownloadedFile();
        $this->downloadJob->addFile($file);
        $this->assertIsArray($this->downloadJob->getFiles()->toArray());
        $this->assertCount(1, $this->downloadJob->getFiles()->toArray());
        $this->assertContains($file, $this->downloadJob->getFiles()->toArray());
        $this->downloadJob->removeFile($file);
        $this->assertIsArray($this->downloadJob->getFiles()->toArray());
        $this->assertCount(0, $this->downloadJob->getFiles()->toArray());
        $this->assertNotContains($file, $this->downloadJob->getFiles()->toArray());
    }
}
