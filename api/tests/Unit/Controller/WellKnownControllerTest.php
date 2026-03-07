<?php

namespace App\Tests\Unit\Controller;

use App\Controller\WellKnownController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class WellKnownControllerTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private WellKnownController $controller;
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        $this->controller = new WellKnownController(self::CLIENT_ID);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $id) => 'router' === $id);
        $container->method('get')->willReturnCallback(fn (string $id) => 'router' === $id ? $this->urlGenerator : null);
        $this->controller->setContainer($container);
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
        $this->assertSame('oauth2', $data['auth_mode']);
        $this->assertSame('dev', $data['version']);
        $this->assertArrayHasKey('oauth2', $data);
        $oauth2 = $data['oauth2'];
        $this->assertSame(self::CLIENT_ID, $oauth2['client_id']);
        $this->assertSame('https://localhost/auth/start-oauth2-flow', $oauth2['authorization_endpoint']);
        $this->assertArrayHasKey('token_endpoint', $oauth2);
        $this->assertArrayHasKey('scopes', $oauth2);
        $this->assertArrayHasKey('openid', $oauth2['scopes']);
        $this->assertArrayHasKey('profile', $oauth2['scopes']);
        $this->assertArrayHasKey('email', $oauth2['scopes']);
        $this->assertArrayHasKey('offline_access', $oauth2['scopes']);
    }

    public function testScopesConstantContainsRequiredScopes(): void
    {
        $this->assertArrayHasKey('openid', WellKnownController::SCOPES);
        $this->assertArrayHasKey('profile', WellKnownController::SCOPES);
        $this->assertArrayHasKey('email', WellKnownController::SCOPES);
        $this->assertArrayHasKey('offline_access', WellKnownController::SCOPES);
    }
}
