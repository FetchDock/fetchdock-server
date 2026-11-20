<?php

namespace App\Service\Downloader\Options;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Runs bin/dump_options.py, which reads the option parsers of the installed gallery-dl and yt-dlp.
 */
final class OptionsDumper implements OptionsDumperInterface
{
    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/bin/dump_options.py')]
        private readonly string $scriptPath,
        private readonly string $pythonBinary = 'python3',
    ) {
    }

    public function dump(string $tool): array
    {
        $process = new Process([$this->pythonBinary, $this->scriptPath, $tool]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
            $output = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        } catch (ProcessExceptionInterface|\JsonException $e) {
            $this->logger->error('Downloader options dump failed', ['tool' => $tool, 'exception' => $e, 'error' => $process->getErrorOutput()]);

            throw new \RuntimeException(sprintf('Unable to list the options of %s.', $tool), previous: $e);
        }

        if (!is_array($output) || !is_string($output['version'] ?? null) || !is_array($output['options'] ?? null)) {
            throw new \RuntimeException(sprintf('Unexpected options dump of %s.', $tool));
        }

        return ['version' => $output['version'], 'options' => array_values(array_filter($output['options'], 'is_array'))];
    }
}
