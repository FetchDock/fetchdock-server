<?php

namespace App\Tests\Unit\Service\Downloader;

use App\Entity\DownloadJob;
use App\Enum\DownloaderTypeEnum;
use App\Service\Downloader\AbstractCliDownloader;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AbstractCliDownloaderTest extends TestCase
{
    private TagAwareCacheInterface $cache;
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;
    private string $configPath;
    private string $binaryPath;
    private string $downloadPath;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(TagAwareCacheInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configPath = '/tmp/test-config.conf';
        $this->binaryPath = '/usr/bin/test-binary';
        $this->downloadPath = '/tmp/downloads';
    }

    public function testGetDownloaderType(): void
    {
        $downloader = $this->createConcreteDownloader();

        $this->assertSame(DownloaderTypeEnum::CLI_DOWNLOADER, $downloader->getDownloaderType());
    }

    public function testDownloaderImplementsInterface(): void
    {
        $downloader = $this->createConcreteDownloader();

        $this->assertInstanceOf(\App\Service\Downloader\DownloaderInterface::class, $downloader);
    }

    public function testConstructorSetsProperties(): void
    {
        $downloader = $this->createConcreteDownloader();

        // Use reflection to verify protected properties are set correctly
        $reflection = new \ReflectionClass($downloader);

        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setAccessible(true);
        $this->assertSame($this->cache, $cacheProperty->getValue($downloader));

        $configPathProperty = $reflection->getProperty('configPath');
        $configPathProperty->setAccessible(true);
        $this->assertSame($this->configPath, $configPathProperty->getValue($downloader));

        $binaryPathProperty = $reflection->getProperty('binaryPath');
        $binaryPathProperty->setAccessible(true);
        $this->assertSame($this->binaryPath, $binaryPathProperty->getValue($downloader));

        $downloadPathProperty = $reflection->getProperty('downloadPath');
        $downloadPathProperty->setAccessible(true);
        $this->assertSame($this->downloadPath, $downloadPathProperty->getValue($downloader));

        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $this->assertSame($this->logger, $loggerProperty->getValue($downloader));
    }

    public function testTimeoutConstants(): void
    {
        $reflection = new \ReflectionClass(AbstractCliDownloader::class);

        $this->assertTrue($reflection->hasConstant('TIMEOUT'));
        $this->assertTrue($reflection->hasConstant('IDLE_TIMEOUT'));

        $this->assertSame(1800.0, $reflection->getConstant('TIMEOUT'));
        $this->assertSame(300.0, $reflection->getConstant('IDLE_TIMEOUT'));
    }

    public function testCreateConfigFileIfNotExistsCreatesDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-downloader-' . uniqid();
        $configPath = $tempDir . '/config/test.conf';

        $downloader = $this->createConcreteDownloader($configPath);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Creating config directory', ['path' => dirname($configPath)]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Creating config file', ['path' => $configPath]);

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($downloader);
        $method = $reflection->getMethod('createConfigFileIfNotExists');
        $method->setAccessible(true);

        $method->invoke($downloader);

        $this->assertDirectoryExists(dirname($configPath));
        $this->assertFileExists($configPath);
        $this->assertStringContainsString('test-config-content', file_get_contents($configPath));

        // Cleanup
        unlink($configPath);
        rmdir(dirname($configPath));
        rmdir($tempDir);
    }

    public function testCreateConfigFileIfNotExistsSkipsIfExists(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'existing-config');
        file_put_contents($tempFile, 'existing content');

        $downloader = $this->createConcreteDownloader($tempFile);

        $this->logger->expects($this->never())
            ->method('info');

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($downloader);
        $method = $reflection->getMethod('createConfigFileIfNotExists');
        $method->setAccessible(true);

        $method->invoke($downloader);

        $this->assertSame('existing content', file_get_contents($tempFile));

        // Cleanup
        unlink($tempFile);
    }

    public function testCreateDownloadDirectoryIfNotExists(): void
    {
        $tempDir = sys_get_temp_dir() . '/test-downloads-' . uniqid();

        $downloader = $this->createConcreteDownloader(null, null, $tempDir);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Creating download directory', ['path' => $tempDir]);

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($downloader);
        $method = $reflection->getMethod('createDownloadDirectoryIfNotExists');
        $method->setAccessible(true);

        $method->invoke($downloader);

        $this->assertDirectoryExists($tempDir);

        // Cleanup
        rmdir($tempDir);
    }

    public function testCreateDownloadDirectoryIfNotExistsSkipsIfExists(): void
    {
        $tempDir = sys_get_temp_dir() . '/existing-downloads-' . uniqid();
        mkdir($tempDir);

        $downloader = $this->createConcreteDownloader(null, null, $tempDir);

        $this->logger->expects($this->never())
            ->method('debug');

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($downloader);
        $method = $reflection->getMethod('createDownloadDirectoryIfNotExists');
        $method->setAccessible(true);

        $method->invoke($downloader);

        $this->assertDirectoryExists($tempDir);

        // Cleanup
        rmdir($tempDir);
    }

    private function createConcreteDownloader(?string $configPath = null, ?string $binaryPath = null, ?string $downloadPath = null): AbstractCliDownloader
    {
        return new class(
            $this->cache,
            $this->eventDispatcher,
            $configPath ?? $this->configPath,
            $binaryPath ?? $this->binaryPath,
            $downloadPath ?? $this->downloadPath,
            $this->logger
        ) extends AbstractCliDownloader {
            public function getIdentifier(): string
            {
                return 'test-cli-downloader';
            }

            public function supportsUri(\Psr\Http\Message\UriInterface $uri): bool
            {
                return $uri->getHost() === 'test.com';
            }

            public function getSupportedDomains(): array
            {
                return ['test.com'];
            }

            public function getVersion(): string
            {
                return '1.0.0-test';
            }

            protected function getConfigFileContents(): string
            {
                return 'test-config-content';
            }
        };
    }
}
