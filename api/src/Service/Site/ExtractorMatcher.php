<?php

namespace App\Service\Site;

use App\Model\CanonicalExtractorMatch;
use App\Model\ExtractorMatch;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Runs bin/resolve_url.py, which inspects the extractor classes of the installed gallery-dl and
 * yt-dlp versions, or probes the page with yt-dlp's generic extractor. The URL is passed as an argv
 * element, never through a shell.
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
    private const int MAX_KEY_LENGTH = 128;

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

    public function probe(string $url): array
    {
        $output = $this->run('probe', $url);

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

    public function canonical(string $url): CanonicalExtractorMatch
    {
        return CanonicalExtractorMatch::fromHelperOutput($this->run('canonical', $url));
    }

    public function canonicalBatch(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if ([] === $urls) {
            return [];
        }

        $input = '';
        foreach ($urls as $url) {
            // Not JSON-encodable: left out, so callers fall back to canonical() for it
            if (mb_check_encoding($url, 'UTF-8')) {
                $input .= json_encode($url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            }
        }
        if ('' === $input) {
            return [];
        }

        $process = new Process([$this->pythonBinary, $this->scriptPath, 'canonical-batch']);
        $process->setInput($input);
        // Each URL takes milliseconds once the extractor classes are loaded
        $process->setTimeout(self::TIMEOUT_SECONDS + count($urls));

        $result = [];
        try {
            $process->mustRun();
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                if ('' === $line) {
                    continue;
                }
                $output = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (is_array($output) && is_string($output['url'] ?? null)) {
                    $result[$output['url']] = CanonicalExtractorMatch::fromHelperOutput($output);
                }
            }
        } catch (ProcessExceptionInterface|\JsonException $e) {
            $this->logger->error('Extractor matcher failed', [
                'command' => 'canonical-batch',
                'exception' => $e,
                'error' => $process->getErrorOutput(),
            ]);

            throw new \RuntimeException('Unable to derive canonical media identities.', previous: $e);
        }

        return $result;
    }

    private function toMatch(mixed $extractor): ?ExtractorMatch
    {
        $downloader = self::DOWNLOADERS[$extractor['tool'] ?? ''] ?? null;
        if (null === $downloader || !is_string($extractor['key'] ?? null) || !is_string($extractor['family'] ?? null)
            // SiteExtractor.extractorKey column length; generic keys embed a domain
            || mb_strlen($extractor['key']) > self::MAX_KEY_LENGTH) {
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

            $output = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            if ('' !== $process->getErrorOutput()) {
                $this->logger->debug('Extractor matcher diagnostics', [
                    'command' => $command,
                    'output' => $process->getErrorOutput(),
                ]);
            }

            return $output;
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
