<?php

namespace App\Tests\Unit\Dto;

use App\Dto\DownloadJobDTO;
use PHPUnit\Framework\TestCase;

class DownloadJobDTOTest extends TestCase
{
    private DownloadJobDTO $dto;

    protected function setUp(): void
    {
        $this->dto = new DownloadJobDTO();
    }

    public function testPublicProperties(): void
    {
        $reflection = new \ReflectionClass($this->dto);
        
        $this->assertTrue($reflection->hasProperty('uri'));
        $this->assertTrue($reflection->hasProperty('userAgent'));
        $this->assertTrue($reflection->hasProperty('cookies'));
        $this->assertTrue($reflection->hasProperty('downloader'));
        
        $uriProperty = $reflection->getProperty('uri');
        $this->assertTrue($uriProperty->isPublic());
        
        $userAgentProperty = $reflection->getProperty('userAgent');
        $this->assertTrue($userAgentProperty->isPublic());
        
        $cookiesProperty = $reflection->getProperty('cookies');
        $this->assertTrue($cookiesProperty->isPublic());
        
        $downloaderProperty = $reflection->getProperty('downloader');
        $this->assertTrue($downloaderProperty->isPublic());
    }

    public function testUriProperty(): void
    {
        $uri = 'https://example.com/test.zip';
        $this->dto->uri = $uri;
        
        $this->assertSame($uri, $this->dto->uri);
    }

    public function testUserAgentProperty(): void
    {
        $userAgent = 'TestAgent/1.0';
        $this->dto->userAgent = $userAgent;
        
        $this->assertSame($userAgent, $this->dto->userAgent);
    }

    public function testCookiesProperty(): void
    {
        $cookies = ['session' => 'abc123', 'token' => 'xyz456'];
        $this->dto->cookies = $cookies;
        
        $this->assertSame($cookies, $this->dto->cookies);
    }

    public function testDownloaderProperty(): void
    {
        $downloader = 'yt-dlp-cli';
        $this->dto->downloader = $downloader;
        
        $this->assertSame($downloader, $this->dto->downloader);
    }

    public function testValidationConstraints(): void
    {
        $reflection = new \ReflectionClass($this->dto);
        
        // Check URI property has validation constraints
        $uriProperty = $reflection->getProperty('uri');
        $attributes = $uriProperty->getAttributes();
        
        $constraintTypes = array_map(fn($attr) => $attr->getName(), $attributes);
        
        $this->assertContains(\Symfony\Component\Validator\Constraints\Type::class, $constraintTypes);
        $this->assertContains(\Symfony\Component\Validator\Constraints\Url::class, $constraintTypes);
        $this->assertContains(\Symfony\Component\Validator\Constraints\NotNull::class, $constraintTypes);
        
        // Check downloader property has custom validation constraint
        $downloaderProperty = $reflection->getProperty('downloader');
        $downloaderAttributes = $downloaderProperty->getAttributes();
        
        $downloaderConstraintTypes = array_map(fn($attr) => $attr->getName(), $downloaderAttributes);
        
        $this->assertContains(\Symfony\Component\Validator\Constraints\Type::class, $downloaderConstraintTypes);
        $this->assertContains(\App\Validator\SelectDownloader::class, $downloaderConstraintTypes);
    }

    public function testCompleteDTO(): void
    {
        $this->dto->uri = 'https://youtube.com/watch?v=test123';
        $this->dto->userAgent = 'Mozilla/5.0';
        $this->dto->cookies = ['auth' => 'token123'];
        $this->dto->downloader = 'yt-dlp-cli';
        
        $this->assertSame('https://youtube.com/watch?v=test123', $this->dto->uri);
        $this->assertSame('Mozilla/5.0', $this->dto->userAgent);
        $this->assertSame(['auth' => 'token123'], $this->dto->cookies);
        $this->assertSame('yt-dlp-cli', $this->dto->downloader);
    }

    public function testMinimalDTO(): void
    {
        $this->dto->uri = 'https://example.com/minimal.zip';
        
        $this->assertSame('https://example.com/minimal.zip', $this->dto->uri);
        
        // Other properties should be unset/default
        $this->assertFalse(isset($this->dto->userAgent));
        $this->assertFalse(isset($this->dto->cookies));
        $this->assertFalse(isset($this->dto->downloader));
    }

    public function testDTOIsFinal(): void
    {
        $reflection = new \ReflectionClass($this->dto);
        $this->assertTrue($reflection->isFinal());
    }

    public function testPropertyTypes(): void
    {
        $reflection = new \ReflectionClass($this->dto);
        
        $uriProperty = $reflection->getProperty('uri');
        $this->assertTrue($uriProperty->hasType());
        $this->assertSame('string', $uriProperty->getType()->getName());
        
        $userAgentProperty = $reflection->getProperty('userAgent');
        $this->assertTrue($userAgentProperty->hasType());
        $this->assertSame('string', $userAgentProperty->getType()->getName());
        
        $cookiesProperty = $reflection->getProperty('cookies');
        $this->assertTrue($cookiesProperty->hasType());
        $this->assertSame('array', $cookiesProperty->getType()->getName());
        
        $downloaderProperty = $reflection->getProperty('downloader');
        $this->assertTrue($downloaderProperty->hasType());
        $this->assertSame('string', $downloaderProperty->getType()->getName());
    }
}