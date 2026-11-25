<?php

namespace App\Tests\Unit\Controller;

use App\Controller\WellKnownController;
use App\Enum\IntegrityStatus;
use App\Service\FailureReport\FailureReportForwarder;
use App\Service\Integrity\IntegrityStatusResolver;
use App\Service\Privacy\PrivacyPolicyPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class WellKnownControllerTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const VERSION = 'dev';
    private WellKnownController $controller;
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        $this->controller = $this->controller();
        $this->urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $this->controller->setContainer($this->container());
    }

    private function controller(?string $privacyPolicyUrl = null, IntegrityStatus $integrity = IntegrityStatus::UNKNOWN, bool $failureReports = true): WellKnownController
    {
        $publisher = $this->createStub(PrivacyPolicyPublisher::class);
        $publisher->method('url')->willReturn($privacyPolicyUrl);
        $resolver = $this->createStub(IntegrityStatusResolver::class);
        $resolver->method('effectiveStatus')->willReturn($integrity);

        return new WellKnownController(self::CLIENT_ID, self::VERSION, $publisher, $resolver, $this->createStub(FailureReportForwarder::class), $failureReports);
    }

    private function container(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        $container = $this->createStub(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $id) => 'router' === $id);
        $container->method('get')->willReturnCallback(fn (string $id) => 'router' === $id ? $this->urlGenerator : null);

        return $container;
    }

    public function testConfigForBrowserExtensionReturnsExpectedStructure(): void
    {
        $this->urlGenerator->method('generate')
            ->willReturnCallback(function (string $route) {
                return match ($route) {
                    'app_start_oauth2_flow' => 'https://localhost/auth/start-oauth2-flow',
                    default => '',
                };
            });
        $response = $this->controller->configForBrowserExtension();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('dev', $data['version']);
        $this->assertTrue($data['authentication']['enabled']);
        $this->assertSame('oauth2', $data['authentication']['mode']);
        $oauth2 = $data['authentication']['oauth2'];
        $this->assertSame(self::CLIENT_ID, $oauth2['client_id']);
        $this->assertSame('https://localhost/auth/start-oauth2-flow', $oauth2['authorization_endpoint']);
        $this->assertArrayHasKey('token_endpoint', $oauth2);
        $this->assertArrayHasKey('scopes', $oauth2);
        $this->assertArrayHasKey('openid', $oauth2['scopes']);
        $this->assertArrayHasKey('profile', $oauth2['scopes']);
        $this->assertArrayHasKey('email', $oauth2['scopes']);
        $this->assertArrayHasKey('offline_access', $oauth2['scopes']);

        // Deprecated since 0.6.0, removed in 0.7.0: replaced by authentication.mode and authentication.oauth2
        $this->assertArrayNotHasKey('auth_mode', $data);
        $this->assertArrayNotHasKey('oauth2', $data);
        $this->assertSame([], $data['DEPRECATION_WARNINGS']);

        $this->assertSame(['failureReports' => ['enabled' => true, 'upstreamForwarding' => null]], $data['features']);
        $this->assertNull($data['privacyPolicyUrl']);
        $this->assertSame(['status' => 'unknown', 'appVersion' => 'dev'], $data['integrity']);
    }

    public function testFailureReportsAndPrivacyPolicyAreConfigurable(): void
    {
        $controller = $this->controller('https://example.org/privacy', IntegrityStatus::VERIFIED, failureReports: false);
        $controller->setContainer($this->container());
        $this->urlGenerator->method('generate')->willReturn('https://localhost/');

        $data = json_decode($controller->configForBrowserExtension()->getContent(), true);

        $this->assertFalse($data['features']['failureReports']['enabled']);
        $this->assertSame('https://example.org/privacy', $data['privacyPolicyUrl']);
        $this->assertSame('verified', $data['integrity']['status']);
    }

    public function testScopesConstantContainsRequiredScopes(): void
    {
        $this->assertArrayHasKey('openid', WellKnownController::SCOPES);
        $this->assertArrayHasKey('profile', WellKnownController::SCOPES);
        $this->assertArrayHasKey('email', WellKnownController::SCOPES);
        $this->assertArrayHasKey('offline_access', WellKnownController::SCOPES);
    }
}
