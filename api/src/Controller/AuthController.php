<?php

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Controller responsible for handling authentication-related actions,
 * mainly for the browser extensions and PWA to authenticate with this service using OAuth2.
 *
 * The server acts as a confidential OAuth2 client, handling the full authorization code + PKCE
 * flow on behalf of the browser extension. The extension only needs to open the authorization
 * URL and receive the tokens via the callback page.
 */
final class AuthController extends AbstractController
{
    /**
     * TTL for the PKCE state cache entries (5 minutes).
     */
    private const int AUTH_STATE_TTL = 300;

    public function __construct(
        #[Autowire('%oidc.client_id%')]
        private string $clientId,
        #[Autowire('%oidc.client_secret%')]
        private string $clientSecret,
        #[Autowire('%oidc.well_known_url%')]
        private string $wellKnownUrl,
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/auth', name: 'app_auth')]
    public function index(): Response
    {
        return $this->render('auth/index.html.twig', [
            'controller_name' => 'AuthController',
        ]);
    }

    /**
     * Starts the OAuth2 authorization code flow by redirecting the user to the identity provider.
     *
     * The server generates a PKCE code_verifier and code_challenge pair, stores the code_verifier
     * in cache keyed by a random state parameter, and redirects the user to the IdP's authorization
     * endpoint. The redirect_uri points to the server's own callback endpoint.
     */
    #[Route('/auth/start-oauth2-flow', name: 'app_start_oauth2_flow', methods: ['GET'])]
    public function startOAuth2Flow(): Response
    {
        $authorizationEndpoint = $this->getWellKnownField('authorization_endpoint');
        if (!$authorizationEndpoint) {
            return new JsonResponse(
                ['error' => 'authorization_endpoint_not_found', 'message' => 'Could not resolve the authorization endpoint from the identity provider.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Generate PKCE parameters server-side
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Generate a random state parameter for CSRF protection and to key the cache entry
        $state = bin2hex(random_bytes(32));

        // Store the code_verifier in cache, keyed by state, so the callback can retrieve it
        $cacheKey = $this->getStateCacheKey($state);
        $cacheItem = $this->cache->getItem($cacheKey);
        $cacheItem->set($codeVerifier);
        $cacheItem->expiresAfter(self::AUTH_STATE_TTL);
        $this->cache->save($cacheItem);

        // The redirect_uri points to the server's own callback endpoint
        $redirectUri = $this->generateUrl(
            route: 'app_auth_callback',
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Build OAuth2 query parameters
        $queryParams = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', array_keys(WellKnownController::SCOPES)),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        // Build the full authorization URL and redirect the user to the IdP
        $separator = str_contains($authorizationEndpoint, '?') ? '&' : '?';
        $authorizationUrl = $authorizationEndpoint.$separator.http_build_query($queryParams);

        return $this->redirect($authorizationUrl);
    }

    /**
     * OAuth2 callback endpoint. The IdP redirects here after the user authenticates.
     *
     * This endpoint receives the authorization code and state from the IdP, retrieves the
     * stored PKCE code_verifier from cache, exchanges the code for tokens at the IdP's token
     * endpoint, and renders an HTML page that delivers the tokens to the browser extension.
     */
    #[Route('/auth/callback', name: 'app_auth_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        // Check for IdP error response
        $error = $request->query->get('error');
        if ($error) {
            $errorDescription = $request->query->get('error_description', 'An unknown error occurred during authentication.');

            return $this->render('auth/callback.html.twig', [
                'success' => false,
                'error' => $error,
                'error_description' => $errorDescription,
            ]);
        }

        $code = $request->query->get('code');
        $state = $request->query->get('state');

        if (!$code || !$state) {
            return $this->render('auth/callback.html.twig', [
                'success' => false,
                'error' => 'missing_parameters',
                'error_description' => 'The authorization code or state parameter is missing.',
            ]);
        }

        // Retrieve the code_verifier from cache using the state
        $cacheKey = $this->getStateCacheKey($state);
        $cacheItem = $this->cache->getItem($cacheKey);
        if (!$cacheItem->isHit()) {
            $this->logger->warning('OAuth2 callback received with unknown or expired state', ['state' => $state]);

            return $this->render('auth/callback.html.twig', [
                'success' => false,
                'error' => 'invalid_state',
                'error_description' => 'The authentication session has expired or the state parameter is invalid. Please try again.',
            ]);
        }

        $codeVerifier = $cacheItem->get();

        // Delete the cache entry to prevent replay attacks
        $this->cache->deleteItem($cacheKey);

        // Get the token endpoint from the well-known URL
        $tokenEndpoint = $this->getWellKnownField('token_endpoint');
        if (!$tokenEndpoint) {
            return $this->render('auth/callback.html.twig', [
                'success' => false,
                'error' => 'token_endpoint_not_found',
                'error_description' => 'Could not resolve the token endpoint from the identity provider.',
            ]);
        }

        // The redirect_uri must match exactly what was sent in the authorization request
        $redirectUri = $this->generateUrl(
            route: 'app_auth_callback',
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            $response = $this->httpClient->request('POST', $tokenEndpoint, [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            $this->logger->debug('Token endpoint response', [
                'status_code' => $statusCode,
                'headers' => $response->getHeaders(false),
            ]);

            $tokenData = json_decode($content, true);
            if (\JSON_ERROR_NONE !== json_last_error()) {
                $this->logger->error('Failed to decode token endpoint response', [
                    'error' => json_last_error_msg(),
                    'raw_content' => $content,
                ]);

                return $this->render('auth/callback.html.twig', [
                    'success' => false,
                    'error' => 'invalid_idp_response',
                    'error_description' => 'The identity provider returned an invalid response.',
                ]);
            }

            // If the IdP returned an error (e.g., invalid_grant)
            if (isset($tokenData['error'])) {
                $this->logger->warning('IdP returned an error during token exchange', ['response' => $tokenData]);

                return $this->render('auth/callback.html.twig', [
                    'success' => false,
                    'error' => $tokenData['error'],
                    'error_description' => $tokenData['error_description'] ?? 'The identity provider returned an error.',
                ]);
            }

            // Success: render the callback page with tokens
            return $this->render('auth/callback.html.twig', [
                'success' => true,
                'access_token' => $tokenData['access_token'] ?? null,
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_in' => $tokenData['expires_in'] ?? null,
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to exchange authorization code for token', [
                'exception' => $e->getMessage(),
            ]);

            return $this->render('auth/callback.html.twig', [
                'success' => false,
                'error' => 'token_exchange_failed',
                'error_description' => 'Failed to exchange the authorization code for a token. Please try again.',
            ]);
        }
    }

    #[Route('/auth/code-to-token', name: 'app_auth_code_to_token', methods: ['POST'])]
    public function codeToToken(Request $request): Response
    {
        if(!$request->getContentTypeFormat() === 'json') {
            return new Response('Invalid content type', Response::HTTP_BAD_REQUEST);
        }

        $body = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new Response('Invalid JSON body', Response::HTTP_BAD_REQUEST);
        }

        // get `code` and `code_verifier` from the request
        $code = $body['code'] ?? null;
        $codeVerifier = $body['code_verifier'] ?? null;
        $redirectUri = $body['redirect_uri'] ?? null;

        // Make sure all required parameters are present
        if (!$code || !$codeVerifier || !$redirectUri) {
            return new Response('Missing required parameters', Response::HTTP_BAD_REQUEST);
        }

        // Get the token endpoint from the well-known URL
        $tokenEndpoint = $this->getTokenEndpoint();
        if (!$tokenEndpoint) {
            return new Response('Token endpoint not found', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = $this->httpClient->request('POST', $tokenEndpoint, [
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'code_verifier' => $codeVerifier,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $this->logger->debug(
            'Token endpoint response',
            [
                'status_code' => $response->getStatusCode(),
                'headers' => $response->getHeaders()
            ]
        );

        $decodedContent = json_decode($response->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode token endpoint response', ['error' => json_last_error_msg()]);
            return new Response('Failed to decode token endpoint response', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($decodedContent);
    }

    /**
     * Proxies the token request to the token endpoint.
     * This because the extension can have random ID (referrer)
     * and the token endpoint is not allowed to have a random ID.
     *
     * Therefor the token endpoint is proxied to the server.
     *
     * @param Request $request
     * @return Response
     */
    #[Route('/auth/token', name: 'app_auth_token', methods: ['POST'])]
    public function tokenProxy(Request $request): Response
    {
        $refreshToken = $request->get('refresh_token');

        if (!$refreshToken) {
            return new Response('Missing refresh_token parameter', Response::HTTP_BAD_REQUEST);
        }

        $tokenEndpoint = $this->getTokenEndpoint();
        if (!$tokenEndpoint) {
            return new Response('Token endpoint not found', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = $this->httpClient->request('POST', $tokenEndpoint, [
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
        ]);

        $this->logger->debug(
            'Token endpoint response',
            [
                'status_code' => $response->getStatusCode(),
            ]
        );

        return new Response($response->getContent(), $response->getStatusCode(), $response->getHeaders());
    }

    private function getTokenEndpoint(): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->wellKnownUrl);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = json_decode($response->getContent(), true);
            return $data['token_endpoint'] ?? null;
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            return null;
        }
    }

    /**
     * Fetches a specific field from the OpenID Connect well-known discovery document.
     */
    private function getWellKnownField(string $field): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->wellKnownUrl);
            if (200 !== $response->getStatusCode()) {
                $this->logger->error('Failed to fetch well-known document', [
                    'url' => $this->wellKnownUrl,
                    'status_code' => $response->getStatusCode(),
                ]);

                return null;
            }

            $data = json_decode($response->getContent(), true);
            if (\JSON_ERROR_NONE !== json_last_error()) {
                $this->logger->error('Failed to decode well-known document', [
                    'url' => $this->wellKnownUrl,
                    'error' => json_last_error_msg(),
                ]);

                return null;
            }

            return $data[$field] ?? null;
        } catch (\Exception $e) {
            $this->logger->error('Exception while fetching well-known document', [
                'url' => $this->wellKnownUrl,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generates a cryptographically random PKCE code_verifier (RFC 7636).
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generates a PKCE code_challenge from a code_verifier using S256 method (RFC 7636).
     */
    private function generateCodeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Generates a cache key for storing PKCE state.
     */
    private function getStateCacheKey(string $state): string
    {
        return 'oauth2_state_'.$state;
    }
}
