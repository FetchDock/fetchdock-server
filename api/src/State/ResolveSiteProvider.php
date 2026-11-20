<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\ExtractorReferenceDTO;
use App\Dto\RequestContextDTO;
use App\Dto\SiteReferenceDTO;
use App\Factory\DownloaderFactory;
use App\Resource\SiteResolution;
use App\Security\CurrentOwner;
use App\Service\Settings\CurrentClient;
use App\Service\Settings\EffectiveSettingsResolver;
use App\Service\Site\SiteResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * @implements ProviderInterface<SiteResolution>
 */
final readonly class ResolveSiteProvider implements ProviderInterface
{
    public function __construct(
        private SiteResolver $siteResolver,
        private EffectiveSettingsResolver $effectiveSettingsResolver,
        private DownloaderFactory $downloaderFactory,
        private Security $security,
        private RateLimiterFactoryInterface $siteResolveLimiter,
        private CurrentOwner $currentOwner,
        private CurrentClient $currentClient,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SiteResolution
    {
        /** @var Request $request */
        $request = $context['request'];
        // Validated by the operation's "url" query parameter constraints
        $url = (string) $request->query->get('url');

        $this->consumeRateLimit($request);

        try {
            // Rate limited above, so this endpoint may fetch the page when the offline matcher finds nothing
            $site = $this->siteResolver->resolve($url, probe: true);
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailableHttpException(30, 'Unable to resolve the URL right now.', $e);
        }

        $extractors = [];
        if (null !== $site) {
            foreach ($site->getExtractors() as $extractor) {
                if ($this->downloaderFactory->isValidDownloader($extractor->getDownloader())) {
                    $extractors[] = new ExtractorReferenceDTO($extractor->getDownloader(), $extractor->getExtractorKey());
                }
            }
        }

        $settings = $this->effectiveSettingsResolver->resolve(
            $site,
            array_values(array_unique(array_map(static fn (ExtractorReferenceDTO $e): string => $e->downloader, $extractors))),
            $this->currentOwner->find(),
            $this->currentClient->get(),
        );

        $resolution = new SiteResolution();
        $resolution->url = $url;
        $resolution->host = (string) SiteResolver::getHost($url);
        $resolution->site = null !== $site ? new SiteReferenceDTO($site->getSlug(), $site->getName()) : null;
        $resolution->extractors = $extractors;
        $resolution->effectiveSettings = $settings;
        $resolution->requestContext = new RequestContextDTO(
            cookies: $settings->sendCookies,
            userAgent: $settings->sendUserAgent,
            referrer: $settings->sendReferrer,
        );

        return $resolution;
    }

    private function consumeRateLimit(Request $request): void
    {
        $key = $this->security->getUser()?->getUserIdentifier() ?? (string) $request->getClientIp();
        $limit = $this->siteResolveLimiter->create($key)->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many site resolution requests.');
        }
    }
}
