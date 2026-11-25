<?php

namespace App\Tests\Double;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An in-memory FetchDock collector (ingest API v1) for functional tests. The state is static, so it survives the
 * kernel reboots between test requests; call resetState() in setUp().
 */
final class FakeCollector extends MockHttpClient
{
    public const string URL = 'https://collector.test';

    public static string $policyVersion = '1';
    /** @var list<string> */
    public static array $acceptedVersions = ['1'];
    /** Every call answers this status when set (0 = unreachable) */
    public static ?int $failWith = null;
    /** @var array<string, string> instance secret => instance id */
    public static array $instances = [];
    /** @var array<string, array{instance: string, payload: array<string, mixed>}> report id => report */
    public static array $reports = [];
    /** @var array<string, list<array<string, mixed>>> report id => comments */
    public static array $comments = [];
    /** @var list<string> "METHOD /path" */
    public static array $requests = [];

    public function __construct()
    {
        parent::__construct(static fn (string $method, string $url, array $options): ResponseInterface => self::handle($method, $url, $options), self::URL);
    }

    public static function resetState(): void
    {
        self::$policyVersion = '1';
        self::$acceptedVersions = ['1'];
        self::$failWith = null;
        self::$instances = [];
        self::$reports = [];
        self::$comments = [];
        self::$requests = [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function handle(string $method, string $url, array $options): ResponseInterface
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        self::$requests[] = $method.' '.$path;

        if (0 === self::$failWith) {
            return new MockResponse('', ['error' => 'Connection refused']);
        }
        if (null !== self::$failWith) {
            return self::json(['detail' => 'Simulated failure'], self::$failWith);
        }

        $body = isset($options['body']) && \is_string($options['body']) && '' !== $options['body'] ? json_decode($options['body'], true) : null;
        $secret = null;
        foreach ($options['headers'] ?? [] as $header) {
            if (preg_match('/^authorization: Bearer (.+)$/i', (string) $header, $m)) {
                $secret = $m[1];
            }
        }
        $instance = null !== $secret ? (self::$instances[$secret] ?? null) : null;

        if ('GET' === $method && '/v1/policy' === $path) {
            return self::json(['version' => self::$policyVersion, 'url' => self::URL.'/policy', 'acceptedVersions' => self::$acceptedVersions]);
        }
        if ('POST' === $method && '/v1/instances' === $path) {
            if (!\in_array($body['acceptedPolicyVersion'] ?? null, self::$acceptedVersions, true)) {
                return self::json(['detail' => 'Policy version not accepted'], 422);
            }
            $id = Uuid::v4()->toRfc4122();
            $newSecret = bin2hex(random_bytes(16));
            self::$instances[$newSecret] = $id;

            return self::json(['instanceId' => $id, 'instanceSecret' => $newSecret], 201);
        }
        if (null === $instance) {
            return self::json(['detail' => 'Unknown instance'], 401);
        }
        if ('POST' === $method && '/v1/reports' === $path) {
            foreach (['consent' => $body['consent']['policyVersion'] ?? null, 'operator' => $body['operatorPolicyVersion'] ?? null] as $version) {
                if (!\in_array($version, self::$acceptedVersions, true)) {
                    return self::json(['detail' => 'Policy version not accepted'], 422);
                }
            }
            $id = Uuid::v4()->toRfc4122();
            self::$reports[$id] = ['instance' => $instance, 'payload' => $body];

            return self::json(['id' => $id], 201);
        }
        if (preg_match('#^/v1/reports/([0-9a-f-]{36})(/comments)?$#', $path, $m)) {
            $report = self::$reports[$m[1]] ?? null;
            if (null === $report || $report['instance'] !== $instance) {
                return self::json(['detail' => 'Not found'], 404);
            }
            if ('POST' === $method && isset($m[2])) {
                self::$comments[$m[1]][] = $body;

                return self::json(['id' => Uuid::v4()->toRfc4122()], 201);
            }
            if ('DELETE' === $method && !isset($m[2])) {
                unset(self::$reports[$m[1]], self::$comments[$m[1]]);

                return new MockResponse('', ['http_code' => 204]);
            }
        }

        return self::json(['detail' => 'No route'], 404);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
