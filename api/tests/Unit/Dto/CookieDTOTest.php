<?php

namespace App\Tests\Unit\Dto;

use App\Dto\CookieDTO;
use PHPUnit\Framework\TestCase;

class CookieDTOTest extends TestCase
{
    private CookieDTO $dto;

    protected function setUp(): void
    {
        $this->dto = new CookieDTO();
    }

    public function testPublicProperties(): void
    {
        $reflection = new \ReflectionClass($this->dto);

        $this->assertTrue($reflection->hasProperty('domain'));
        $this->assertTrue($reflection->hasProperty('expirationDate'));
        $this->assertTrue($reflection->hasProperty('hostOnly'));
        $this->assertTrue($reflection->hasProperty('httpOnly'));
        $this->assertTrue($reflection->hasProperty('name'));
        $this->assertTrue($reflection->hasProperty('path'));
        $this->assertTrue($reflection->hasProperty('sameSite'));
        $this->assertTrue($reflection->hasProperty('secure'));
        $this->assertTrue($reflection->hasProperty('session'));
        $this->assertTrue($reflection->hasProperty('value'));

        $domainProperty = $reflection->getProperty('domain');
        $this->assertTrue($domainProperty->isPublic());

        $expirationDateProperty = $reflection->getProperty('expirationDate');
        $this->assertTrue($expirationDateProperty->isPublic());

        $hostOnlyProperty = $reflection->getProperty('hostOnly');
        $this->assertTrue($hostOnlyProperty->isPublic());

        $httpOnlyProperty = $reflection->getProperty('httpOnly');
        $this->assertTrue($httpOnlyProperty->isPublic());

        $nameProperty = $reflection->getProperty('name');
        $this->assertTrue($nameProperty->isPublic());

        $pathProperty = $reflection->getProperty('path');
        $this->assertTrue($pathProperty->isPublic());

        $sameSiteProperty = $reflection->getProperty('sameSite');
        $this->assertTrue($sameSiteProperty->isPublic());

        $secureProperty = $reflection->getProperty('secure');
        $this->assertTrue($secureProperty->isPublic());

        $valueProperty = $reflection->getProperty('value');
        $this->assertTrue($valueProperty->isPublic());

        $sessionProperty = $reflection->getProperty('session');
        $this->assertTrue($sessionProperty->isPublic());
    }

    public function testToNetscapeCookieLine(): void
    {
        $this->dto->name = 'test_cookie';
        $this->dto->value = 'test_value';
        $this->dto->domain = 'example.com';
        $this->dto->path = '/';
        $this->dto->expirationDate = new \DateTime('+1 hour');

        $expectedLine = "example.com\tTRUE\t/\tTRUE\t{$this->dto->expirationDate->format('U')}\ttest_cookie\ttest_value\n";
        $this->assertSame($expectedLine, $this->dto->toNetscapeCookieLine());
    }

    public function testCookieDTOFromPayloadExample1(): void
    {
        $this->dto = CookieDTO::fromArray([
            'name' => 'test_cookie',
            'value' => 'test_value',
            'domain' => 'example.com',
            'path' => '/',
            'expirationDate' => '2026-04-20T00:00:00+00:00Z',
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'unspecified',
        ]);

        $this->assertSame('test_cookie', $this->dto->name);
        $this->assertSame('test_value', $this->dto->value);
        $this->assertSame('example.com', $this->dto->domain);
        $this->assertSame('/', $this->dto->path);
        $this->assertInstanceOf(\DateTimeInterface::class, $this->dto->expirationDate);
        $this->assertSame('2026-04-20T00:00:00+00:00', $this->dto->expirationDate->format('c'));
        $this->assertFalse($this->dto->secure);


        $expectedNetscapeLine = "example.com\tTRUE\t/\tFALSE\t1776643200\ttest_cookie\ttest_value\n";
        $this->assertSame($expectedNetscapeLine, $this->dto->toNetscapeCookieLine());
    }
}
