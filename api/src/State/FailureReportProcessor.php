<?php

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\DownloadJob;
use App\Entity\FailureReport;
use App\Entity\OidcSubjectIdentifier;
use App\Entity\Site;
use App\Enum\DownloadStateEnum;
use App\Repository\FailureReportRepository;
use App\Security\CurrentOwner;
use App\Service\Canonical\CanonicalKeyGenerator;
use App\Service\Site\SiteResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /users/failure_reports: stores the report as sent (ADR 0012) with its owner and the derived host, site and
 * canonical key. Nothing is copied from the linked job.
 *
 * @implements ProcessorInterface<FailureReport, FailureReport>
 */
final readonly class FailureReportProcessor implements ProcessorInterface
{
    /** A repeated report of the same media and kind by the same user within this window is a 409 */
    public const string DUPLICATE_WINDOW = '-24 hours';

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private CurrentOwner $currentOwner,
        private IriConverterInterface $iriConverter,
        private SiteResolver $siteResolver,
        private CanonicalKeyGenerator $canonicalKeyGenerator,
        private FailureReportRepository $failureReportRepository,
        private RateLimiterFactoryInterface $failureReportLimiter,
        private LoggerInterface $logger,
        #[Autowire('%app.failure_reports.enabled%')]
        private bool $enabled,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FailureReport
    {
        if (!$this->enabled) {
            throw new AccessDeniedHttpException('Failure reports are disabled on this server.');
        }

        $owner = $this->currentOwner->get();
        $data->setOwner($owner);

        $requestedJob = $data->getDownloadJobIri();
        if (null !== $requestedJob) {
            $data->setDownloadJob($this->failedJob($requestedJob, $owner));
        }

        $url = (string) $data->getUrl();
        $site = $this->resolveSite($url);
        $data
            ->setHost((string) SiteResolver::getHost($url))
            ->setSite($site)
            ->setCanonicalKey($this->canonicalKeyGenerator->generate($url, $site)->key);

        $duplicate = $this->failureReportRepository->findRecentDuplicate(
            $owner,
            $data->getCanonicalKey(),
            $data->getKind(),
            new \DateTimeImmutable(self::DUPLICATE_WINDOW),
        );
        if (null !== $duplicate) {
            throw new ConflictHttpException(sprintf('You already reported this within the last 24 hours: /users/failure_reports/%s', $duplicate->getId()->toRfc4122()));
        }

        $limit = $this->failureReportLimiter->create((string) $owner->getSubject())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many failure reports, try again later.');
        }

        $this->logger->info('Failure report received', ['kind' => $data->getKind()?->value, 'host' => $data->getHost(), 'site' => $site?->getSlug()]);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * The owner's FAILED job behind the IRI; 422 for anything else. Other users' jobs are not found, as the
     * owner filter applies.
     */
    private function failedJob(string $iri, OidcSubjectIdentifier $owner): DownloadJob
    {
        try {
            $job = $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable) {
            $job = null;
        }

        if (!$job instanceof DownloadJob || $job->getOwner()?->getId() !== $owner->getId()) {
            $this->invalid('Download job not found.');
        }
        if (DownloadStateEnum::FAILED !== $job->getState()) {
            $this->invalid('Only failed download jobs can be reported.');
        }

        return $job;
    }

    private function resolveSite(string $url): ?Site
    {
        try {
            // No network probe: reporting must not make the server fetch the page
            return $this->siteResolver->resolve($url);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Unable to resolve the site of a failure report', ['exception' => $e]);

            return null;
        }
    }

    private function invalid(string $message): never
    {
        throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, 'downloadJob', null)]));
    }
}
