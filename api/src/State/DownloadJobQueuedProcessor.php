<?php

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\DownloadJobDTO;
use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Factory\DownloaderFactory;
use GuzzleHttp\Psr7\Uri;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use ApiPlatform\Symfony\Messenger\Processor as MessengerProcessor;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;


class DownloadJobQueuedProcessor implements ProcessorInterface
{
    public function __construct(
        /**
         * @var PersistProcessor $persistProcessor
         */
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private MessengerProcessor $messengerProcessor,
        private DownloaderFactory $downloaderFactory,
        private TagAwareCacheInterface $cache
    )
    {
    }

    /**
     * @param DownloadJobDTO $data
     * @param Operation $operation
     * @param array $uriVariables
     * @param array $context
     * @return void
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        // Validate downloader
        if (isset($data->downloader) && !$this->downloaderFactory->isValidDownloader($data->downloader)) {
            throw new BadRequestException(
                'Invalid downloader specified. Possible values: ' . implode(', ', array_map(fn($d) => $d->getIdentifier(), $this->downloaderFactory->getEnabledDownloaders()))
            );
        }

        $downloadJob = new DownloadJob()
            ->setUri($data->uri)
            ->setUserAgent($data->userAgent ?? null)
            ->setCookies($data->cookies ?? null)
            ->setState(DownloadStateEnum::PENDING);

        if (isset($data->downloader)) {
            $downloadJob->setDownloader($data->downloader);
        } else {

            $downloaderKey = $this->cache->get($this->getDomainProbeCacheKey($downloadJob), function (ItemInterface $item) use ($downloadJob) {
                $item->expiresAfter(3600); // Cache for 1 hour

                $item->tag([
                    'dlsupport', // Global tag for all download support checks
                    // Tag for the specific domain, so we can invalidate it if needed
                    $this->getDomainProbeCacheKey($downloadJob)
                ]);
                // Try to determine the downloader by URI
                // This will for example run "yt-dlp --simulate <uri>" to see if yt-dlp supports the given URI
                $downloaders = $this->downloaderFactory->getDownloadersByUri(new Uri($downloadJob->getUrl()));
                foreach ($downloaders as $downloader) {
                    // Cache for 24 hours if a downloader was found
                    $item->expiresAfter(86400);
                    return $downloader->getIdentifier();
//                    $downloadJob->setDownloader($downloader->getIdentifier());
                    break; // Use the first matching downloader
                }

                return false; // No downloader found
            });
            if ($downloaderKey) {
                $downloadJob->setDownloader($downloaderKey);
            } else {
                // Invalidate the cache for this domain, so we can try again next time
                // This is useful if a site was not supported before, but is supported now
                $this->cache->invalidateTags([$this->getDomainProbeCacheKey($downloadJob)]);
            }

            if (!$downloadJob->getDownloader()) {
                throw new BadRequestException(
                    'No downloader found for the given URI. Possible values: ' . implode(', ', array_map(fn($d) => $d->getIdentifier(), $this->downloaderFactory->getEnabledDownloaders()))
                );
            }
        }

        // Try converting the uri to a valid URI
        // If it fails, throw a 400 error
        try {
            $uri = new \GuzzleHttp\Psr7\Uri($downloadJob->getUri());
        } catch (\Exception $e) {
            throw new BadRequestException('Invalid URI');
        }

        // Persist the job as a queued job
        $this->persistProcessor->process($downloadJob,$operation,$uriVariables,$context);

        // Dispatch the job to the messenger queue
        return $this->messengerProcessor->process($downloadJob,$operation,$uriVariables,$context);
    }

    private function getDomainProbeCacheKey(DownloadJob $downloadJob): string
    {
        // Use the domain as the cache key
        // ie: dlsupport_example.com
        return 'dlsupport_' . $downloadJob->getUrl()->getHost();
    }
}
