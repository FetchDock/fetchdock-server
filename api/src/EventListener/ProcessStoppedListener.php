<?php

namespace App\EventListener;

use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Enum\DownloadStateEnum;
use App\Event\CliProcessStopEvent;
use App\Factory\DownloaderFactory;
use App\Repository\DownloadJobRepository;
use App\Service\Downloader\AbstractCliDownloader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

readonly class ProcessStoppedListener
{
    public function __construct(
        private LoggerInterface        $logger,
        private EntityManagerInterface $entityManager,
        private DownloadJobRepository  $downloadJobRepository,
        private DownloaderFactory      $downloaderFactory
    )
    {
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

        /** @var DownloadJob $downloadJob */
        $downloadJob = $this->downloadJobRepository->find($event->downloadJob->getId());
        if ($downloadJob) {
            // Create a new DownloadJobEvent to log the process stop
            $jobEvent = new DownloadJobEvent()
                ->setDownloadJob($downloadJob)
                ->setEvent('process.stopped')
                ->setSource('listener')
                ->setUpdateMessage('Process stopped with exit code ' . $event->exitCode . ' (' . $event->exitCodeText . ')')
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

        if($downloadJob->getState() !== DownloadStateEnum::COMPLETED) {
            return;
        }

        if($downloadJob->getDownloader() === null) {
            $downloader = $this->downloaderFactory->getDownloadersByUri($downloadJob->getUrl());
        } else {
            $downloader = $this->downloaderFactory->getDownloaderByIdentifier($downloadJob->getDownloader());
        }

        if($downloader === null) {
            return;
        }

        if($downloader instanceof AbstractCliDownloader) {
            $downloader->addFilesToDownloadJobFromCommandOutput(
                $downloadJob,
                $event->process->getOutput()
            );
        }
    }
}
