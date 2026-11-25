<?php

namespace App\Service\FailureReport;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The FetchDock collector's ingest API v1 (ADR 0014, contracts/collector-openapi.json). Every call except the policy
 * and the registration carries the instance secret as bearer token.
 */
class CollectorClient
{
    private const string POLICY_CACHE_KEY = 'failure_report_collector_policy.';
    private const int POLICY_TTL = 3600;
    /** An unreachable collector is asked again after this, so the well-known document doesn't wait on it each time */
    private const int POLICY_FAILURE_TTL = 300;

    private readonly string $collectorUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.failure_reports.collector_url%')]
        string $collectorUrl,
    ) {
        $this->collectorUrl = rtrim(trim($collectorUrl), '/');
    }

    /** Forwarding is unavailable when the operator configured no collector */
    public function isConfigured(): bool
    {
        return '' !== $this->collectorUrl;
    }

    public function getCollectorUrl(): string
    {
        return $this->collectorUrl;
    }

    /**
     * The collector's policy, cached for an hour; null while the collector can't be reached.
     */
    public function policy(): ?CollectorPolicy
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $item = $this->cache->getItem(self::POLICY_CACHE_KEY.hash('xxh128', $this->collectorUrl));
        if ($item->isHit()) {
            $cached = $item->get();

            return \is_array($cached) ? CollectorPolicy::fromArray($cached) : null;
        }

        try {
            $policy = $this->fetchPolicy();
            $this->cache->save($item->set($policy->toArray())->expiresAfter(self::POLICY_TTL));
        } catch (CollectorException $e) {
            $this->logger->warning('Cannot fetch the collector policy', ['error' => $e->getMessage()]);
            $policy = null;
            $this->cache->save($item->set(false)->expiresAfter(self::POLICY_FAILURE_TTL));
        }

        return $policy;
    }

    /**
     * Fetches the policy now and refreshes the cache.
     *
     * @throws CollectorException
     */
    public function fetchPolicy(): CollectorPolicy
    {
        $data = $this->json($this->request('GET', '/v1/policy'));
        $policy = CollectorPolicy::fromArray($data) ?? throw new CollectorException('The collector returned an invalid policy.', 502);

        $item = $this->cache->getItem(self::POLICY_CACHE_KEY.hash('xxh128', $this->collectorUrl));
        $this->cache->save($item->set($policy->toArray())->expiresAfter(self::POLICY_TTL));

        return $policy;
    }

    /**
     * Registers this instance (POST /v1/instances).
     *
     * @return array{instanceId: Uuid, instanceSecret: string}
     *
     * @throws CollectorException
     */
    public function register(string $serverVersion, string $acceptedPolicyVersion): array
    {
        $data = $this->json($this->request('POST', '/v1/instances', json: [
            'serverVersion' => mb_substr($serverVersion, 0, 64),
            'acceptedPolicyVersion' => $acceptedPolicyVersion,
        ]));
        $id = $data['instanceId'] ?? null;
        $secret = $data['instanceSecret'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id) || !\is_string($secret) || '' === $secret) {
            throw new CollectorException('The collector returned an invalid registration.', 502);
        }

        return ['instanceId' => Uuid::fromString($id), 'instanceSecret' => $secret];
    }

    /**
     * POST /v1/reports.
     *
     * @param array<string, mixed> $payload an UpstreamFailureReport
     *
     * @throws CollectorException
     */
    public function submitReport(string $secret, array $payload): Uuid
    {
        return $this->createdId($this->request('POST', '/v1/reports', $secret, $payload));
    }

    /**
     * POST /v1/reports/{id}/comments.
     *
     * @throws CollectorException
     */
    public function addComment(string $secret, Uuid $reportId, string $comment, \DateTimeImmutable $createdAt): Uuid
    {
        return $this->createdId($this->request('POST', '/v1/reports/'.$reportId->toRfc4122().'/comments', $secret, [
            'comment' => $comment,
            'createdAt' => $createdAt->format(\DATE_ATOM),
        ]));
    }

    /**
     * DELETE /v1/reports/{id}. A report the collector doesn't know (404) counts as deleted.
     *
     * @throws CollectorException
     */
    public function deleteReport(string $secret, Uuid $reportId): void
    {
        try {
            $this->request('DELETE', '/v1/reports/'.$reportId->toRfc4122(), $secret);
        } catch (CollectorException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @throws CollectorException
     */
    private function request(string $method, string $path, ?string $secret = null, ?array $json = null): ResponseInterface
    {
        if (!$this->isConfigured()) {
            throw new CollectorException('No collector is configured (FAILURE_REPORTS_COLLECTOR_URL).', 503);
        }

        $options = ['timeout' => 10, 'max_duration' => 30, 'headers' => ['Accept' => 'application/json']];
        if (null !== $secret) {
            $options['auth_bearer'] = $secret;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $this->collectorUrl.$path, $options);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new CollectorException('The collector could not be reached: '.$e->getMessage(), null, $e);
        }

        if ($status >= 300) {
            throw new CollectorException(\sprintf('The collector answered %d: %s', $status, $this->problem($response)), $status);
        }

        return $response;
    }

    /**
     * @return array<mixed>
     *
     * @throws CollectorException
     */
    private function json(ResponseInterface $response): array
    {
        try {
            return $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new CollectorException('The collector returned invalid JSON.', 502, $e);
        }
    }

    /**
     * @throws CollectorException
     */
    private function createdId(ResponseInterface $response): Uuid
    {
        $id = $this->json($response)['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new CollectorException('The collector returned no id.', 502);
        }

        return Uuid::fromString($id);
    }

    /** The problem detail of an error response, for admins and logs */
    private function problem(ResponseInterface $response): string
    {
        try {
            $data = $response->toArray(false);
        } catch (ExceptionInterface) {
            return 'no details';
        }

        $violations = array_map(
            static fn (mixed $v): string => \is_array($v) ? trim(($v['propertyPath'] ?? '').': '.($v['message'] ?? ''), ': ') : '',
            \is_array($data['violations'] ?? null) ? $data['violations'] : [],
        );
        $detail = $data['detail'] ?? $data['title'] ?? null;
        $text = implode('; ', array_filter([\is_string($detail) ? $detail : null, ...$violations]));

        return '' === $text ? 'no details' : mb_substr($text, 0, 1000);
    }
}
