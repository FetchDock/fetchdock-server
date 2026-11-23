<?php

namespace App\EventListener;

use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Enum\DownloadStateEnum;
use App\Event\CliProcessStopEvent;
use App\Exception\DownloadJobDeletedException;
use App\Factory\DownloaderFactory;
use App\Message\PurgeFilesMessage;
use App\Repository\DownloadJobRepository;
use App\Service\Downloader\AbstractCliDownloader;
use App\Service\Downloader\DownloaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessStoppedListener
{
    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private DownloadJobRepository $downloadJobRepository,
        private DownloaderFactory $downloaderFactory,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Runs first: when the owner deleted their account while the job ran (server#129), there is nothing to record. The
     * files the tool reported are purged, and the exception stops the job (DownloadJobHandler).
     */
    #[AsEventListener(event: CliProcessStopEvent::class, priority: 100)]
    public function abortWhenJobWasDeleted(CliProcessStopEvent $event): void
    {
        $downloadJob = $event->downloadJob;
        $jobId = $downloadJob->getId();
        if (null === $jobId || $this->downloadJobRepository->existsInDatabase($jobId)) {
            return;
        }

        $downloader = $this->downloaderFor($downloadJob);
        $paths = $downloader instanceof AbstractCliDownloader
            ? $downloader->filePathsFromCommandOutput($downloadJob, $event->process->getOutput())
            : [];
        if ([] !== $paths) {
            $this->messageBus->dispatch(new PurgeFilesMessage(
                paths: $paths,
                writtenSince: $downloadJob->getCreatedAt()?->getTimestamp(),
            ));
        }

        throw new DownloadJobDeletedException($jobId);
    }

    #[AsEventListener(event: CliProcessStopEvent::class)]
    public function onCliProcessStopEvent(CliProcessStopEvent $event): void
    {
        $this->logger->info('CLI process stopped', [
            'command' => $event->process->getCommandLine(),
            'exit_code' => $event->exitCode,
            'is_successful' => $event->wasSuccessful,
            'job_id' => $event->downloadJob->getId(),
            'uri' => $event->downloadJob->getUri(),
        ]);

        // CLI commands such as app:url:download build a DownloadJob that is never
        // persisted, so its id is null. Doctrine cannot find() a null identifier
        // and throws MissingIdentifierField; there is nothing to record for such
        // a job, so skip the database work entirely.
        $jobId = $event->downloadJob->getId();
        if (null === $jobId) {
            return;
        }

        /** @var DownloadJob $downloadJob */
        $downloadJob = $this->downloadJobRepository->find($jobId);
        if ($downloadJob) {
            // Create a new DownloadJobEvent to log the process stop
            $jobEvent = new DownloadJobEvent()
                ->setDownloadJob($downloadJob)
                ->setEvent('process.stopped')
                ->setSource('listener')
                ->setUpdateMessage('Process stopped with exit code '.$event->exitCode.' ('.$event->exitCodeText.')')
                ->setContext([
                    'command' => $event->process->getCommandLine(),
                    'exit_code' => $event->exitCode,
                    'is_successful' => $event->wasSuccessful,
                    'error_output' => $event->errorOutput,
                    'output' => [
                        'stdOut' => $event->process->getOutput(),
                        'stdErr' => $event->process->getErrorOutput(),
                    ],
                ]);
            $this->entityManager->persist($jobEvent);

            // Update the job status based on the process result
            if ($event->wasSuccessful) {
                $downloadJob->setState(DownloadStateEnum::COMPLETED);
            } else {
                $downloadJob->setState(DownloadStateEnum::FAILED);
            }
            $this->entityManager->persist($downloadJob);
            $this->entityManager->flush();
        }
    }

    #[AsEventListener(event: CliProcessStopEvent::class)]
    public function addFilesToDownloadJobFromCommandOutput(CliProcessStopEvent $event): void
    {
        $downloadJob = $event->downloadJob;

        if (DownloadStateEnum::COMPLETED !== $downloadJob->getState()) {
            return;
        }

        $downloader = $this->downloaderFor($downloadJob);
        if ($downloader instanceof AbstractCliDownloader) {
            $downloader->addFilesToDownloadJobFromCommandOutput(
                $downloadJob,
                $event->process->getOutput()
            );
        }
    }

    private function downloaderFor(DownloadJob $downloadJob): ?DownloaderInterface
    {
        if (null !== $downloadJob->getDownloader()) {
            return $this->downloaderFactory->getDownloaderByIdentifier($downloadJob->getDownloader());
        }

        foreach ($this->downloaderFactory->getDownloadersByDownloadJob($downloadJob) as $downloader) {
            return $downloader;
        }

        return null;
    }
}
