<?php

namespace App\Tests\Unit\Controller;

use App\Controller\AuthController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AuthControllerTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const CLIENT_SECRET = 'test-client-secret';
    private const WELL_KNOWN_URL = 'https://idp.example.com/.well-known/openid-configuration';
    private const AUTHORIZATION_ENDPOINT = 'https://idp.example.com/authorize';
    private const TOKEN_ENDPOINT = 'https://idp.example.com/token';
    private const CALLBACK_URL = 'https://localhost/auth/callback';

    private HttpClientInterface&MockObject $httpClient;
    private CacheItemPoolInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new AuthController(
            self::CLIENT_ID,
            self::CLIENT_SECRET,
            self::WELL_KNOWN_URL,
            $this->httpClient,
            $this->cache,
            $this->logger,
        );

        // AbstractController needs a container for redirect(), generateUrl(), render()
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')
            ->willReturnCallback(fn(string $route) => match ($route) {
                'app_auth_callback' => self::CALLBACK_URL,
                default => '',
            });

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html>mock</html>');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn(string $id) => in_array($id, ['router', 'twig']));
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            'router' => $this->urlGenerator,
            'twig' => $twig,
            default => null,
        });

        $this->controller->setContainer($container);
    }

    // --- startOAuth2Flow tests ---

    public function testStartOAuth2FlowRedirectsToIdpWithPkceAndState(): void
    {
        $this->mockWellKnownResponse();
        $this->mockCacheSave();

        $response = $this->controller->startOAuth2Flow();

        $this->assertSame(302, $response->getStatusCode());
        $locationHeader = $response->headers->get('Location');
        $this->assertNotNull($locationHeader);

        // Parse the redirect URL
        parse_str(parse_url($locationHeader, \PHP_URL_QUERY) ?? '', $queryParams);

        $this->assertSame(self::CLIENT_ID, $queryParams['client_id']);
        $this->assertSame(self::CALLBACK_URL, $queryParams['redirect_uri']);
        $this->assertSame('code', $queryParams['response_type']);
        $this->assertStringContainsString('openid', $queryParams['scope']);
        $this->assertStringContainsString('profile', $queryParams['scope']);
        $this->assertStringContainsString('email', $queryParams['scope']);
        $this->assertStringContainsString('offline_access', $queryParams['scope']);
        $this->assertSame('S256', $queryParams['code_challenge_method']);
        $this->assertNotEmpty($queryParams['code_challenge']);
        $this->assertNotEmpty($queryParams['state']);
    }

    public function testStartOAuth2FlowStoresCodeVerifierInCache(): void
    {
        $this->mockWellKnownResponse();

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('set')->with($this->callback(fn($v) => is_string($v)));
        $cacheItem->expects($this->once())->method('expiresAfter')->with(300);

        $this->cache->expects($this->once())->method('getItem')
            ->with($this->stringStartsWith('oauth2_state_'))
            ->willReturn($cacheItem);
        $this->cache->expects($this->once())->method('save')->with($cacheItem);

        $this->controller->startOAuth2Flow();
    }

    public function testStartOAuth2FlowReturnsErrorWhenAuthorizationEndpointNotFound(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([]));
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $response = $this->controller->startOAuth2Flow();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('authorization_endpoint_not_found', $data['error']);
    }

    public function testStartOAuth2FlowHandlesWellKnownFailure(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(500);
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $response = $this->controller->startOAuth2Flow();

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testStartOAuth2FlowHandlesWellKnownHttpException(): void
    {
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $response = $this->controller->startOAuth2Flow();

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testStartOAuth2FlowCodeChallengeIsDerivedFromVerifier(): void
    {
        $this->mockWellKnownResponse();

        $storedVerifier = null;
        $cacheItem = $this->createMock(CacheItemInterface::class);
//        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $cacheItem->method('set')->willReturnCallback(function ($value) use (&$storedVerifier, $cacheItem) {
            $storedVerifier = $value;
            return $cacheItem;
        });

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        $response = $this->controller->startOAuth2Flow();

        $locationHeader = $response->headers->get('Location');
        parse_str(parse_url($locationHeader, \PHP_URL_QUERY) ?? '', $queryParams);

        // Verify the code_challenge is a valid base64url-encoded SHA256 of the verifier
        $this->assertNotNull($storedVerifier, 'Code verifier should have been stored in cache');
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $storedVerifier, true)), '+/', '-_'), '=');
        $this->assertSame($expectedChallenge, $queryParams['code_challenge']);
    }

    public function testStartOAuth2FlowRedirectUrlPointsToAuthorizationEndpoint(): void
    {
        $this->mockWellKnownResponse();
        $this->mockCacheSave();

        $response = $this->controller->startOAuth2Flow();

        $locationHeader = $response->headers->get('Location');
        $parsedUrl = parse_url($locationHeader);

        $this->assertSame('idp.example.com', $parsedUrl['host']);
        $this->assertSame('/authorize', $parsedUrl['path']);
    }

    public function testStartOAuth2FlowGeneratesUniqueStatePerRequest(): void
    {
        $this->mockWellKnownResponse();

        $states = [];
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('expiresAfter')->willReturnSelf();
        $cacheItem->method('set')->willReturnSelf();
        $this->cache->method('getItem')->willReturnCallback(function (string $key) use ($cacheItem, &$states) {
            $states[] = str_replace('oauth2_state_', '', $key);
            return $cacheItem;
        });
        $this->cache->method('save')->willReturn(true);

        $this->controller->startOAuth2Flow();
        $this->controller->startOAuth2Flow();

        $this->assertCount(2, $states);
        $this->assertNotSame($states[0], $states[1], 'Each request should generate a unique state');
    }

    public function testStartOAuth2FlowHandlesWellKnownInvalidJson(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn('not-valid-json');
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $response = $this->controller->startOAuth2Flow();

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    // --- callback tests ---

    public function testCallbackExchangesCodeForTokensSuccessfully(): void
    {
        $tokenResponseData = [
            'access_token' => 'eyJhbGciOiJSUzI1NiJ9.test',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-token-value',
        ];

        $this->mockCacheHit('test-state', 'test-code-verifier');
        $this->mockTokenExchange($tokenResponseData);

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'auth-code-from-idp',
            'state' => 'test-state',
        ]);

        $response = $this->controller->callback($request);

        // The controller renders a Twig template (mocked), so we get a 200 HTML response
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackDeletesCacheEntryToPreventReplay(): void
    {
        $tokenResponseData = [
            'access_token' => 'test-token',
            'token_type' => 'Bearer',
        ];

        $this->mockCacheHit('replay-state', 'verifier');
        $this->cache->expects($this->once())->method('deleteItem')
            ->with('oauth2_state_replay-state');
        $this->mockTokenExchange($tokenResponseData);

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'some-code',
            'state' => 'replay-state',
        ]);

        $this->controller->callback($request);
    }

    public function testCallbackRendersErrorWhenIdpeturnsError(): void
    {
        $request = Request::create('/auth/callback', 'GET', [
            'error' => 'access_denied',
            'error_description' => 'User denied consent.',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenCodeIsMissing(): void
    {
        $request = Request::create('/auth/callback', 'GET', [
            'state' => 'some-state',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenStateIsMissing(): void
    {
        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'some-code',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenStateNotFoundInCache(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'some-code',
            'state' => 'expired-state',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenTokenEndpointNotFound(): void
    {
        $this->mockCacheHit('test-state', 'verifier');

        // Well-known returns no token_endpoint
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([]));
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'some-code',
            'state' => 'test-state',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenIdpReturnsTokenError(): void
    {
        $this->mockCacheHit('test-state', 'verifier');

        $idpError = [
            'error' => 'invalid_grant',
            'error_description' => 'The authorization code has expired.',
        ];
        $this->mockTokenExchange($idpError, 400);

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'expired-code',
            'state' => 'test-state',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenTokenExchangeThrowsException(): void
    {
        $this->mockCacheHit('test-state', 'verifier');

        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method) use ($wellKnownResponse) {
                if ('GET' === $method) {
                    return $wellKnownResponse;
                }
                throw new \RuntimeException('Connection timed out');
            });

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'some-code',
            'state' => 'test-state',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenIdpReturnsInvalidJson(): void
    {
        $this->mockCacheHit('test-state', 'verifier');

        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenResponse->method('getContent')->willReturn('not-json');
        $tokenResponse->method('getHeaders')->willReturn([]);

        $this->httpClient->method('request')
            ->willReturnCallback(fn(string $method) => 'GET' === $method ? $wellKnownResponse : $tokenResponse);

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'some-code',
            'state' => 'test-state',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackSendsCorrectParametersToTokenEndpoint(): void
    {
        $this->mockCacheHit('verify-params-state', 'my-code-verifier');

        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'authorization_endpoint' => self::AUTHORIZATION_ENDPOINT,
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenResponse->method('getContent')->willReturn(json_encode([
            'access_token' => 'token',
            'token_type' => 'Bearer',
        ]));
        $tokenResponse->method('getHeaders')->willReturn([]);

        $capturedOptions = null;
        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options = []) use ($wellKnownResponse, $tokenResponse, &$capturedOptions) {
                if ('GET' === $method) {
                    return $wellKnownResponse;
                }
                $capturedOptions = $options;
                return $tokenResponse;
            });

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'the-auth-code',
            'state' => 'verify-params-state',
        ]);

        $this->controller->callback($request);

        $this->assertNotNull($capturedOptions, 'Token exchange request should have been made');
        $body = $capturedOptions['body'] ?? [];
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('the-auth-code', $body['code']);
        $this->assertSame('my-code-verifier', $body['code_verifier']);
        $this->assertSame(self::CLIENT_ID, $body['client_id']);
        $this->assertSame(self::CALLBACK_URL, $body['redirect_uri']);
    }

    public function testCallbackRendersErrorWhenBothCodeAndStateAreMissing(): void
    {
        $request = Request::create('/auth/callback', 'GET');

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackRendersErrorWhenWellKnownFailsDuringTokenExchange(): void
    {
        $this->mockCacheHit('test-state', 'verifier');

        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(500);
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'some-code',
            'state' => 'test-state',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCallbackHandlesIdpErrorWithoutDescription(): void
    {
        $request = Request::create('/auth/callback', 'GET', [
            'error' => 'server_error',
        ]);

        $response = $this->controller->callback($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- codeToToken tests ---

    public function testCodeToTokenExchangesCodeSuccessfully(): void
    {
        $tokenResponseData = [
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'new-refresh-token',
        ];

        $this->mockTokenExchange($tokenResponseData);

        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'auth-code',
            'code_verifier' => 'pkce-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('new-access-token', $data['access_token']);
        $this->assertSame('new-refresh-token', $data['refresh_token']);
    }

    public function testCodeToTokenReturnsBadRequestWhenNoContentTypeIsSpecified(): void
    {
        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [], json_encode([
            'code' => 'auth-code',
            'code_verifier' => 'pkce-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsBadRequestWhenCodeIsMissing(): void
    {
        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code_verifier' => 'pkce-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsBadRequestWhenCodeVerifierIsMissing(): void
    {
        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'auth-code',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsBadRequestWhenRedirectUriIsMissing(): void
    {
        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'auth-code',
            'code_verifier' => 'pkce-verifier',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsBadRequestWhenBodyIsEmpty(): void
    {
        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsBadRequestWhenBodyIsInvalidJson(): void
    {
        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

//    public function testCodeToTokenReturnsBadRequestWhenResponseContainsMalformedJson(): void
//    {
//        $wellKnownResponse = $this->createMock(ResponseInterface::class);
//        $wellKnownResponse->method('getStatusCode')->willReturn(500);
//        $wellKnownResponse->method('getContent')->willReturn('{"token_endpoint": "https://idp.example.com/token"}');
//        $this->httpClient->method('request')->willReturn($wellKnownResponse);
//
//        $this->httpClient->method('request')
//            ->willReturnCallback(function (string $method, string $url, array $options = []) {
//                $response = $this->createMock(ResponseInterface::class);
//                $response->method('getStatusCode')->willReturn(200);
//                $response->method('getContent')->willReturn('404 Not Found'); // JSON expected, but plain text returned
//                $response->method('getHeaders')->willReturn([]);
//                return $response;
//            });
//
//        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
//            'CONTENT_TYPE' => 'application/json',
//        ], json_encode([
//            'code' => 'auth-code',
//            'code_verifier' => 'pkce-verifier',
//            'redirect_uri' => 'https://extension/callback',
//        ])); // Missing closing brace
//
//        $response = $this->controller->codeToToken($request);
//
//        $this->assertInstanceOf(JsonResponse::class, $response);
//        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
//        $this->assertJson($response->getContent());
//        $data = json_decode($response->getContent(), true);
//        $this->assertFalse($data['success']);
//        $this->assertSame('Failed to decode token endpoint response', $data['message']);
//
//    }

    public function testCodeToTokenReturnsErrorWhenTokenEndpointNotFound(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([]));
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'auth-code',
            'code_verifier' => 'pkce-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsErrorWhenIdpRejectsCode(): void
    {
        $this->mockTokenExchange([
            'error' => 'invalid_grant',
            'error_description' => 'Code expired',
        ], 400);

        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'expired-code',
            'code_verifier' => 'pkce-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsErrorWhenWellKnownFails(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(500);
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'auth-code',
            'code_verifier' => 'pkce-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testCodeToTokenReturnsErrorWhenHttpClientThrows(): void
    {
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'auth-code',
            'code_verifier' => 'pkce-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $response = $this->controller->codeToToken($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testCodeToTokenSendsCorrectParametersToIdp(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'authorization_endpoint' => self::AUTHORIZATION_ENDPOINT,
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenResponse->method('getContent')->willReturn(json_encode([
            'access_token' => 'token',
            'token_type' => 'Bearer',
        ]));
        $tokenResponse->method('getHeaders')->willReturn([]);

        $capturedOptions = null;
        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options = []) use ($wellKnownResponse, $tokenResponse, &$capturedOptions) {
                if ('GET' === $method) {
                    return $wellKnownResponse;
                }
                $capturedOptions = $options;
                return $tokenResponse;
            });

        $request = Request::create('/auth/code-to-token', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => 'the-code',
            'code_verifier' => 'the-verifier',
            'redirect_uri' => 'https://extension/callback',
        ]));

        $this->controller->codeToToken($request);

        $this->assertNotNull($capturedOptions, 'Token exchange request should have been made');
        $body = $capturedOptions['body'] ?? [];
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('the-code', $body['code']);
        $this->assertSame('the-verifier', $body['code_verifier']);
        $this->assertSame(self::CLIENT_ID, $body['client_id']);
        $this->assertSame('https://extension/callback', $body['redirect_uri']);
    }

    // --- refreshToken tests ---

    public function testRefreshTokenExchangesRefreshTokenSuccessfully(): void
    {
        $tokenResponseData = [
            'access_token' => 'refreshed-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'new-refresh-token',
        ];

        $this->mockTokenExchangeForRefresh($tokenResponseData);

        $request = Request::create('/auth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'old-refresh-token',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $response = $this->controller->tokenProxy($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('refreshed-access-token', $data['access_token']);
        $this->assertSame('new-refresh-token', $data['refresh_token']);
    }

    public function testRefreshTokenReturnsBadRequestWhenRefreshTokenIsMissing(): void
    {
        $request = Request::create('/auth/token', 'POST', [
            'grant_type' => 'refresh_token',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $response = $this->controller->tokenProxy($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRefreshTokenReturnsErrorWhenTokenEndpointNotFound(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([]));
        $this->httpClient->method('request')->willReturn($wellKnownResponse);

        $request = Request::create('/auth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'old-token',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $response = $this->controller->tokenProxy($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testRefreshTokenReturnsErrorWhenIdpRejectsRefreshToken(): void
    {
        $this->mockTokenExchangeForRefresh([
            'error' => 'invalid_grant',
            'error_description' => 'Refresh token expired',
        ], 400);

        $request = Request::create('/auth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'expired-refresh-token',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $response = $this->controller->tokenProxy($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testRefreshTokenReturnsErrorWhenHttpClientThrows(): void
    {
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $request = Request::create('/auth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'some-token',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $response = $this->controller->tokenProxy($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testRefreshTokenSendsCorrectParametersToIdp(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenResponse->method('getContent')->willReturn(json_encode([
            'access_token' => 'token',
            'token_type' => 'Bearer',
        ]));
        $tokenResponse->method('getHeaders')->willReturn([]);

        $capturedOptions = null;
        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options = []) use ($wellKnownResponse, $tokenResponse, &$capturedOptions) {
                if ('GET' === $method) {
                    return $wellKnownResponse;
                }
                $capturedOptions = $options;
                return $tokenResponse;
            });

        $request = Request::create('/auth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'my-refresh-token',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $this->controller->tokenProxy($request);

        $this->assertNotNull($capturedOptions, 'Refresh token request should have been made');
        $body = $capturedOptions['body'] ?? [];
        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame('my-refresh-token', $body['refresh_token']);
        $this->assertSame(self::CLIENT_ID, $body['client_id']);
        $this->assertSame(self::CLIENT_SECRET, $body['client_secret']);
    }

    public function testRefreshTokenReturnsBadRequestWhenBodyIsEmpty(): void
    {
        $request = Request::create('/auth/token', 'POST');
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $response = $this->controller->tokenProxy($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // --- Helper methods ---

    private function mockWellKnownResponse(): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'authorization_endpoint' => self::AUTHORIZATION_ENDPOINT,
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $this->httpClient->method('request')->willReturn($wellKnownResponse);
    }

    private function mockCacheSave(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);
    }

    private function mockCacheHit(string $state, string $codeVerifier): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($codeVerifier);

        $this->cache->method('getItem')
            ->with('oauth2_state_' . $state)
            ->willReturn($cacheItem);
    }

    private function mockTokenExchange(array $responseData, int $statusCode = 200): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'authorization_endpoint' => self::AUTHORIZATION_ENDPOINT,
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn($statusCode);
        $tokenResponse->method('getContent')->willReturn(json_encode($responseData));
        $tokenResponse->method('getHeaders')->willReturn([]);

        $this->httpClient->method('request')
            ->willReturnCallback(fn(string $method) => 'GET' === $method ? $wellKnownResponse : $tokenResponse);
    }

    private function mockTokenExchangeForRefresh(array $responseData, int $statusCode = 200): void
    {
        $wellKnownResponse = $this->createMock(ResponseInterface::class);
        $wellKnownResponse->method('getStatusCode')->willReturn(200);
        $wellKnownResponse->method('getContent')->willReturn(json_encode([
            'token_endpoint' => self::TOKEN_ENDPOINT,
        ]));

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn($statusCode);
        $tokenResponse->method('getContent')->willReturn(json_encode($responseData));
        $tokenResponse->method('getHeaders')->willReturn([]);

        $this->httpClient->method('request')
            ->willReturnCallback(fn(string $method) => 'GET' === $method ? $wellKnownResponse : $tokenResponse);
    }
}
