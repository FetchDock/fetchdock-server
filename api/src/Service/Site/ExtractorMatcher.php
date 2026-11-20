<?php

namespace App\Service\Site;

use App\Model\ExtractorMatch;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Runs bin/resolve_url.py, which inspects the extractor classes of the installed gallery-dl and
 * yt-dlp versions. The URL is passed as an argv element, never through a shell.
 */
final class ExtractorMatcher implements ExtractorMatcherInterface
{
    /**
     * Tool name used by the helper => downloader identifier (see App\Factory\DownloaderFactory).
     */
    public const array DOWNLOADERS = [
        'gallery-dl' => 'gallery-dl-cli',
        'yt-dlp' => 'yt-dlp-cli',
    ];

    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/bin/resolve_url.py')]
        private readonly string $scriptPath,
        private readonly string $pythonBinary = 'python3',
    ) {
    }

    public function match(string $url): array
    {
        $output = $this->run('resolve', $url);

        return array_values(array_filter(array_map($this->toMatch(...), $output['extractors'] ?? [])));
    }

    public function catalog(array $families): array
    {
        if ([] === $families) {
            return [];
        }

        $output = $this->run('catalog', json_encode(array_values($families), JSON_THROW_ON_ERROR));

        $catalog = [];
        foreach ($output['extractors'] ?? [] as $extractor) {
            $match = $this->toMatch($extractor);
            if (null !== $match) {
                $catalog[] = ['match' => $match, 'hosts' => array_values(array_filter($extractor['hosts'] ?? [], 'is_string'))];
            }
        }

        return $catalog;
    }

    private function toMatch(mixed $extractor): ?ExtractorMatch
    {
        $downloader = self::DOWNLOADERS[$extractor['tool'] ?? ''] ?? null;
        if (null === $downloader || !is_string($extractor['key'] ?? null) || !is_string($extractor['family'] ?? null)) {
            return null;
        }

        return new ExtractorMatch($downloader, $extractor['key'], $extractor['family']);
    }

    private function run(string $command, string $argument): array
    {
        $process = new Process([$this->pythonBinary, $this->scriptPath, $command, $argument]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();

            return json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        } catch (ProcessExceptionInterface|\JsonException $e) {
            $this->logger->error('Extractor matcher failed', [
                'command' => $command,
                'exception' => $e,
                'error' => $process->getErrorOutput(),
            ]);

            throw new \RuntimeException('Unable to match the URL to downloader extractors.', previous: $e);
        }
    }
}
