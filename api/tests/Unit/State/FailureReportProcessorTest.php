<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\FailureReport;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\FailureReportKind;
use App\Repository\FailureReportRepository;
use App\Security\CurrentOwner;
use App\Service\FailureReport\FailureReportForwarder;
use App\Service\Site\SiteResolver;
use App\State\FailureReportProcessor;
use App\Tests\Double\CanonicalKeyGenerators;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class FailureReportProcessorTest extends TestCase
{
    public function testReportsAreRateLimitedPerUser(): void
    {
        $processor = $this->createProcessor(limit: 2);

        $processor->process($this->report(), new Post());
        $processor->process($this->report(), new Post());

        $this->expectException(TooManyRequestsHttpException::class);
        $processor->process($this->report(), new Post());
    }

    public function testDisabledReportsAreRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->createProcessor(limit: 5, enabled: false)->process($this->report(), new Post());
    }

    public function testDerivesHostAndCanonicalKey(): void
    {
        $report = $this->createProcessor(limit: 5)->process($this->report('https://WWW.Example.com/a?b=1#c'), new Post());

        $this->assertSame('www.example.com', $report->getHost());
        $this->assertNull($report->getSite());
        $this->assertStringStartsWith('url:', $report->getCanonicalKey());
        $this->assertSame('https://WWW.Example.com/a?b=1#c', $report->getUrl());
    }

    private function report(string $url = 'https://example.com/page'): FailureReport
    {
        return new FailureReport()->setKind(FailureReportKind::UNSUPPORTED_URL)->setUrl($url);
    }

    private function createProcessor(int $limit, bool $enabled = true): FailureReportProcessor
    {
        $persist = $this->createStub(ProcessorInterface::class);
        $persist->method('process')->willReturnArgument(0);
        $currentOwner = $this->createStub(CurrentOwner::class);
        $currentOwner->method('get')->willReturn(new OidcSubjectIdentifier()->setSubject('user'));
        $siteResolver = $this->createStub(SiteResolver::class);
        $siteResolver->method('resolve')->willReturn(null);

        return new FailureReportProcessor(
            $persist,
            $currentOwner,
            $this->createStub(IriConverterInterface::class),
            $siteResolver,
            CanonicalKeyGenerators::create(),
            $this->createStub(FailureReportRepository::class),
            new RateLimiterFactory(['id' => 'failure_report', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            $this->createStub(FailureReportForwarder::class),
            new NullLogger(),
            $enabled,
        );
    }
}
