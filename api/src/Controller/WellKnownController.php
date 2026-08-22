<?php

namespace App\Controller;

use App\Util\Version;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class WellKnownController extends AbstractController
{
    public const array SCOPES = [
        'openid' => 'Access to your OpenID Connect identity information',
        'profile' => 'Access to your profile information',
        'email' => 'Access to your email address',
        'offline_access' => 'Access to your refresh token',
    ];

    public function __construct(
        #[Autowire('%oidc.client_id%')]
        private string $clientId,
        #[Autowire('%app.version%')]
        private string $appVersion
    ) {
    }

    /**
     * Endpoint to provide configuration for browser extensions.
     *
     * This endpoint will provide configuration required for using this service,
     * such as authentication methods, OAuth2 endpoints, etc.
     * This will be used by browser extensions to configure themselves to work with this service.
     */
    #[Route('/.well-known/browser-extension', name: 'app_well_known_browser_extension')]
    public function configForBrowserExtension(): Response
    {
        return $this->json(
            [
                'DEPRECATION_WARNINGS' => [
                    '.auth_mode' => 'WILL BE REMOVED ON NEXT BROWSER EXTENSION UPDATE. USE .auth.mode INSTEAD',
                    '.oauth2.*' => 'WILL BE REMOVED ON NEXT BROWSER EXTENSION UPDATE. USE .auth.oauth2.* INSTEAD'
                ],
                'authentication' => [
                    'enabled' => true,
                    'mode' => 'oauth2',
                    'oauth2' => [
                        'client_id' => $this->clientId,
                        'authorization_endpoint' => $this->generateUrl(
                            route: 'app_start_oauth2_flow',
                            referenceType: UrlGeneratorInterface::ABSOLUTE_URL
                        ),
                        'token_endpoint' => $this->generateUrl(
                            route: 'app_auth_token',
                            referenceType: UrlGeneratorInterface::ABSOLUTE_URL
                        ),
                        'scopes' => self::SCOPES,
                    ]
                ],
                'auth_mode' => 'oauth2',
                'oauth2' => [
                    'client_id' => $this->clientId,
                    'authorization_endpoint' => $this->generateUrl(
                        route: 'app_start_oauth2_flow',
                        referenceType: UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                    'token_endpoint' => $this->generateUrl(
                        route: 'app_auth_token',
                        referenceType: UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                    'scopes' => self::SCOPES,
                ],
                'config' => [
                    'mercure' => [
                        'enabled' => true,
                        'hub_url' => sprintf(
                            "%s.well-known/mercure",
                            $this->generateUrl(
                                route: 'api_entrypoint',
                                referenceType: UrlGeneratorInterface::ABSOLUTE_URL
                            )
                        )
                    ],
                ],
                'version' => $this->appVersion
            ]
        );
    }
}
