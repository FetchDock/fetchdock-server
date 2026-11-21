<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Resource\Admin\AdminCanonicalization;
use App\Service\Canonical\CanonicalKeyGenerator;
use App\Service\Site\SiteResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Canonicalizes a URL like a submission does (offline site resolution, then CanonicalKeyGenerator).
 *
 * @implements ProviderInterface<AdminCanonicalization>
 */
final readonly class AdminCanonicalizationProvider implements ProviderInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private SiteResolver $siteResolver,
        private CanonicalKeyGenerator $canonicalKeyGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminCanonicalization
    {
        // Validated by the operation's "url" query parameter
        $url = (string) $this->requestStack->getCurrentRequest()?->query->getString('url');

        try {
            $site = $this->siteResolver->resolve($url);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Unable to resolve the site of a URL to canonicalize', ['exception' => $e]);
            $site = null;
        }
        $key = $this->canonicalKeyGenerator->generate($url, $site);

        $result = new AdminCanonicalization();
        $result->url = $url;
        $result->key = $key->key;
        $result->layer = $key->layer;
        $result->profile = $key->profile;
        $result->site = $site?->getSlug();

        return $result;
    }
}
