<?php

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Symfony\Messenger\Processor as MessengerProcessor;
use App\Dto\DownloadJobDTO;
use App\Dto\JobAcceptedDTO;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Enum\DownloadStateEnum;
use App\Enum\JobTypeEnum;
use App\Factory\DownloaderFactory;
use App\Model\DownloadJobInterface;
use App\Repository\DownloadJobRepository;
use App\Repository\OidcSubjectIdentifierRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        #[Autowire(service: MessengerProcessor::class)]
        private ProcessorInterface $messengerProcessor,
        private LoggerInterface $logger,
        private DownloaderFactory $downloaderFactory,
        private TagAwareCacheInterface $cache,
        private Security $security,
        private OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository,
        private DownloadJobRepository $downloadJobRepository,
    ) {
    }

    /**
     * @param DownloadJobDTO $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JobAcceptedDTO
    {
        $downloadJob = new DownloadJob();

        $securityUser = $this->security->getUser();



        $downloadJob
            ->setUri($data->uri)
            ->setUserAgent($data->userAgent ?? null)
            ->setCookies($data->cookies ?? null)
            ->setState(DownloadStateEnum::PENDING);

        if ($securityUser) {
            $oidcSubjectIdentifier = $this->oidcSubjectIdentifierRepository->findOneBy(['subject' => $securityUser->getUserIdentifier()]);
            if (!$oidcSubjectIdentifier) {
                throw new \RuntimeException('OIDC subject identifier not found. Subject: ' . $securityUser->getUserIdentifier());
            }
            $downloadJob->setOwner($oidcSubjectIdentifier);

            if(!$data->force) {
                $this->checkForExistingDownloadJobs($downloadJob);
            }
        }

        $this->logger->debug('Processing new download job', [
            'uri' => $data->uri,
            'data' => $data,
            'operation' => $operation->getName(),
            'uriVariables' => $uriVariables,
            'context' => $context,
        ]);

        // Validate downloader
        if (isset($data->downloader) && !$this->downloaderFactory->isValidDownloader($data->downloader)) {
            throw new BadRequestException('Invalid downloader specified. Possible values: '.implode(', ', array_map(fn ($d) => $d->getIdentifier(), $this->downloaderFactory->getEnabledDownloaders())));
        }

        // If this URI was downloaded before (e.g. by another user) and its files still exist,
        // link those files to a new job instead of downloading again.
        // "force" explicitly requests a fresh download.
        if (!$data->force && $this->reusePreviousDownload($downloadJob, $data->downloader ?? null)) {
            $this->persistProcessor->process($downloadJob, $operation, $uriVariables, $context);

            return new JobAcceptedDTO()
                ->setJobUuid($downloadJob->getUuid()->toRfc4122())
                ->setToken($downloadJob->getToken())
                ->setJobType(JobTypeEnum::DOWNLOAD);
        }

        if (isset($data->downloader)) {
            $downloadJob->setDownloader($data->downloader);
        } else {
            $downloaderKey = $this->cache->get($this->getDomainProbeCacheKey($downloadJob), function (ItemInterface $item) use ($downloadJob) {
                $item->expiresAfter(3600); // Cache for 1 hour

                $item->tag([
                    'dlsupport', // Global tag for all download support checks
                    // Tag for the specific domain, so we can invalidate it if needed
                    $this->getDomainProbeCacheKey($downloadJob),
                ]);
                // Try to determine the downloader by URI
                // This will for example run "yt-dlp --simulate <uri>" to see if yt-dlp supports the given URI
                $downloaders = $this->downloaderFactory->getDownloadersByDownloadJob($downloadJob);
                foreach ($downloaders as $downloader) {
                    $this->logger->debug('Downloader supports URI', [
                        'uri' => $downloadJob->getUri(),
                        'downloader' => $downloader->getIdentifier(),
                    ]);
                    // Cache for 24 hours if a downloader was found
                    $item->expiresAfter(86400);

                    return $downloader->getIdentifier();
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
                throw new BadRequestException('No downloader found for the given URI. Possible values: '.implode(', ', array_map(fn ($d) => $d->getIdentifier(), $this->downloaderFactory->getEnabledDownloaders())));
            }
        }

        // Try converting the uri to a valid URI
        // If it fails, throw a 400 error
        if (false === filter_var($downloadJob->getUri(), FILTER_VALIDATE_URL)) {
            throw new BadRequestException('Invalid URI');
        }

        // Persist the job as a queued job
        $this->persistProcessor->process($downloadJob, $operation, $uriVariables, $context);

        // Dispatch the job to the messenger queue
        $this->messengerProcessor->process($downloadJob, $operation, $uriVariables, $context);

        // Return a JobAcceptedDTO with the job UUID and token
        return new JobAcceptedDTO()
            ->setJobUuid($downloadJob->getUuid()->toRfc4122())
            ->setToken($downloadJob->getToken())
            ->setJobType(JobTypeEnum::DOWNLOAD);
    }

    private function checkForExistingDownloadJobs(DownloadJob $downloadJob): void
    {
        $result = $this->downloadJobRepository->findByUrlAndOwner(
            $downloadJob->getUri(),
            $downloadJob->getOwner()
        );

        if($result) {
            /** @var DownloadJobInterface $existingDownloadJob */
            foreach($result as $existingDownloadJob) {
                if(in_array($existingDownloadJob->getState(), [
                    DownloadStateEnum::PENDING,
                    DownloadStateEnum::COMPLETED,
                    DownloadStateEnum::ALREADY_EXISTS
                ], true)) {
                    throw new ConflictHttpException('Download Job already exists');
                }
            }
        }
    }

    /**
     * Turn the new job into an ALREADY_EXISTS job linked to the files of a previous download of the same URI.
     *
     * Only the files are shared. Nothing else from the previous job (its event log, cookies,
     * user agent or owner) is copied, as that may contain another user's data.
     *
     * @return bool true if a previous download was reused
     */
    private function reusePreviousDownload(DownloadJob $downloadJob, ?string $requestedDownloader): bool
    {
        foreach ($this->downloadJobRepository->findReusableByUri($downloadJob->getUri()) as $previousJob) {
            $previousDownloader = $previousJob->getDownloader();
            if (null === $previousDownloader) {
                continue;
            }
            // An explicitly requested downloader may produce different files, so only reuse matching jobs
            if (null !== $requestedDownloader && $requestedDownloader !== $previousDownloader) {
                continue;
            }

            $existingFiles = $previousJob->getFiles()->filter(
                static fn (DownloadedFile $file) => null !== $file->getPath() && is_file($file->getPath())
            );
            if ($existingFiles->isEmpty()) {
                continue;
            }

            $restoredFiles = 0;
            foreach ($existingFiles as $file) {
                // Files soft-deleted because no live job used them anymore are restored, as they're still on disk
                if ($file->isDeleted()) {
                    $file->setDeletedAt(null);
                    ++$restoredFiles;
                }
                $downloadJob->addFile($file);
            }

            $downloadJob
                ->setDownloader($previousDownloader)
                ->setState(DownloadStateEnum::ALREADY_EXISTS)
                ->addDownloadJobEvent(new DownloadJobEvent()
                    ->setEvent('job.loaded_from_cache')
                    ->setSource('api')
                    ->setUpdateMessage('Previously downloaded job. Loaded from cache')
                    ->setContext(['files' => $existingFiles->count()]));

            $this->logger->info('Reusing files of a previous download job', [
                'uri' => $downloadJob->getUri(),
                'job_uuid' => $downloadJob->getUuid()?->toRfc4122(),
                'previous_job_id' => $previousJob->getId(),
                'files' => $existingFiles->count(),
                'restored_files' => $restoredFiles,
            ]);

            return true;
        }

        return false;
    }

    private function getDomainProbeCacheKey(DownloadJob $downloadJob): string
    {
        // Use the domain as the cache key
        // ie: dlsupport_example.com
        return 'dlsupport_'.$downloadJob->getUrl()->getHost();
    }
}
