<?php

namespace App\Tests\Unit\Service\Downloader;

use App\Entity\DownloadJob;
use App\Enum\DownloaderTypeEnum;
use App\Service\Downloader\MockDownloader;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class MockDownloaderTest extends TestCase
{
    private MockDownloader $downloader;

    protected function setUp(): void
    {
        $this->downloader = new MockDownloader();
    }

    public function testGetIdentifier(): void
    {
        $this->assertSame('mock', $this->downloader->getIdentifier());
    }

    public function testGetDownloaderType(): void
    {
        $this->assertSame(DownloaderTypeEnum::CLI_DOWNLOADER, $this->downloader->getDownloaderType());
    }

    public function testGetSupportedDomains(): void
    {
        $expectedDomains = ['example.com', 'test.com'];
        $this->assertSame($expectedDomains, $this->downloader->getSupportedDomains());
    }

    public function testGetVersion(): void
    {
        $version = $this->downloader->getVersion();
        $this->assertIsString($version);
        $this->assertSame('1.0.0-mock', $version);
    }

    public function testSupportsUriWithSupportedDomain(): void
    {
        $uri = new Uri('https://example.com/file.zip');
        $this->assertTrue($this->downloader->supportsUri($uri));
    }

    public function testSupportsUriWithAnotherSupportedDomain(): void
    {
        $uri = new Uri('https://test.com/video.mp4');
        $this->assertTrue($this->downloader->supportsUri($uri));
    }

    public function testSupportsUriWithUnsupportedDomain(): void
    {
        $uri = new Uri('https://unsupported.com/file.zip');
        $this->assertFalse($this->downloader->supportsUri($uri));
    }

    public function testSupportsUriWithSubdomain(): void
    {
        $uri = new Uri('https://subdomain.example.com/file.zip');
        $this->assertFalse($this->downloader->supportsUri($uri));
    }

    public function testSupportsUriWithDifferentScheme(): void
    {
        $uri = new Uri('http://example.com/file.zip');
        $this->assertTrue($this->downloader->supportsUri($uri));
    }

    public function testSupportsUriWithPort(): void
    {
        $uri = new Uri('https://example.com:8080/file.zip');
        $this->assertTrue($this->downloader->supportsUri($uri));
    }

    public function testDownload(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        
        $result = $this->downloader->download($downloadJob);
        
        $this->assertTrue($result);
    }

    public function testDownloadWithDifferentJob(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://test.com/video.mp4');
        $downloadJob->setUserAgent('TestAgent/1.0');
        $downloadJob->setCookies(['session' => 'abc123']);
        
        $result = $this->downloader->download($downloadJob);
        
        $this->assertTrue($result);
    }

    public function testConsistentBehavior(): void
    {
        // Test that the mock downloader behaves consistently
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/consistent.zip');
        
        // Multiple calls should return the same result
        $result1 = $this->downloader->download($downloadJob);
        $result2 = $this->downloader->download($downloadJob);
        
        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertSame($result1, $result2);
    }

    /**
     * Test that the mock downloader doesn't change based on URI content
     */
    public function testDownloadDoesNotDependOnUriContent(): void
    {
        $job1 = new DownloadJob();
        $job1->setUri('https://example.com/file1.zip');
        
        $job2 = new DownloadJob();
        $job2->setUri('https://example.com/file2.mp4');
        
        $this->assertTrue($this->downloader->download($job1));
        $this->assertTrue($this->downloader->download($job2));
    }

    /**
     * Test edge cases for domain matching
     */
    public function testDomainMatchingEdgeCases(): void
    {
        $testCases = [
            'https://example.com' => true,
            'https://example.com/' => true,
            'https://example.com/path' => true,
            'https://example.com/path?query=1' => true,
            'https://example.com:443/path' => true,
            'http://example.com' => true,
            'ftp://example.com' => true,
            'https://www.example.com' => false,
            'https://sub.example.com' => false,
            'https://examplexcom' => false,
            'https://example.org' => false,
        ];

        foreach ($testCases as $uriString => $expected) {
            $uri = new Uri($uriString);
            $this->assertSame(
                $expected, 
                $this->downloader->supportsUri($uri),
                "Failed for URI: $uriString"
            );
        }
    }
}