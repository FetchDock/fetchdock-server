<?php

namespace App\Tests\Unit\Handler;

use App\Dto\DownloadJobDTO;
use App\Handler\DownloadJobDTOHandler;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class DownloadJobDTOHandlerTest extends TestCase
{
    public function testHandlerIsInstantiable(): void
    {
        $handler = new DownloadJobDTOHandler();
        $this->assertInstanceOf(DownloadJobDTOHandler::class, $handler);
    }

    public function testHandlerHasInvokeMethod(): void
    {
        $handler = new DownloadJobDTOHandler();
        $this->assertTrue(method_exists($handler, '__invoke'));
    }

    public function testHandlerIsCallable(): void
    {
        $handler = new DownloadJobDTOHandler();
        $this->assertTrue(is_callable($handler));
    }

    public function testHandlerAcceptsDownloadJobDTO(): void
    {
        $handler = new DownloadJobDTOHandler();
        $reflection = new \ReflectionMethod($handler, '__invoke');
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        
        $parameter = $parameters[0];
        $this->assertEquals('downloadJob', $parameter->getName());
        $this->assertEquals(DownloadJobDTO::class, (string) $parameter->getType());
    }
}
