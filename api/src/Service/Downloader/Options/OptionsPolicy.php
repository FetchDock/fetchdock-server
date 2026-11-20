<?php

namespace App\Service\Downloader\Options;

use App\Repository\DownloaderOptionPolicyRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides which downloader options users may configure: the code policy
 * (config/packages/downloader_policy.yaml), then the admin overrides (DownloaderOptionPolicy).
 * The hard denylist below is always enforced and can't be overridden.
 */
class OptionsPolicy
{
    /**
     * Never configurable: output paths, config and input files, commands, proxies, cookies,
     * code evaluation, updates and anything else reading or writing server files.
     */
    public const array HARD_DENY = [
        'yt-dlp-cli' => [
            '--exec', '--no-exec', '--output', '-o', '--paths', '-P', '--batch-file', '-a', '--config-locations',
            '--proxy', '--geo-verification-proxy', '--external-downloader', '--downloader', '--external-downloader-args',
            '--downloader-args', '--print-to-file', '--cookies', '--cookies-from-browser', '--netrc-cmd',
            '--netrc-location', '--load-info-json', '--ffmpeg-location', '--plugin-dirs', '--postprocessor-args',
            '--ppa', '--use-postprocessor', '--cache-dir', '--download-archive', '--enable-file-urls',
            '--client-certificate', '--client-certificate-key', '--client-certificate-password', '--update',
            '--update-to', '--ignore-config', '--print', '--user-agent', '--referer', '--add-headers',
        ],
        'gallery-dl-cli' => [
            '-o', '--option', '--config', '-c', '--config-json', '--config-yaml', '--config-toml', '--config-ignore',
            '--exec', '--exec-after', '--input-file', '-i', '--input-file-comment', '--input-file-delete',
            '--cookies', '-C', '--cookies-export', '--cookies-from-browser', '--proxy', '--filter', '--post-filter',
            '--child-filter', '--postprocessor', '-P', '--postprocessor-option', '-O', '--destination', '-d',
            '--directory', '-D', '--filename', '-f', '--extractors', '-X', '--download-archive', '--write-log',
            '--write-unsupported', '--error-file', '--cache-file', '--update-check', '--user-agent', '-a',
        ],
    ];

    /**
     * Options taking a value with one of these metavars (files, paths, expressions, commands) are hard denied too.
     */
    private const string HARD_DENY_METAVAR_PATTERN = '/(^|[^A-Z])(FILE|PATH|DIR|CERTFILE|KEYFILE|EXPR|CMD)([^A-Z]|$)/';

    /**
     * @param array<string, array{groups?: list<string>, options?: list<string>, deny?: list<string>, overrides?: array<string, array{label?: string, type?: string}>}> $config
     */
    public function __construct(
        #[Autowire(param: 'app.downloader_options_policy')]
        private readonly array $config,
        private readonly DownloaderOptionPolicyRepository $overrideRepository,
    ) {
    }

    public function isHardDenied(string $downloader, OptionDefinition $option): bool
    {
        if ([] !== array_intersect($option->flags, self::HARD_DENY[$downloader] ?? [])) {
            return true;
        }

        return null !== $option->metavar && 1 === preg_match(self::HARD_DENY_METAVAR_PATTERN, $option->metavar);
    }

    /**
     * The options users may configure, with their label and type overrides applied.
     *
     * @param array<string, OptionDefinition> $options all options of the downloader, by key
     *
     * @return array<string, OptionDefinition> by key
     */
    public function filter(string $downloader, array $options): array
    {
        $config = $this->config[$downloader] ?? [];
        $groups = $config['groups'] ?? [];
        $allowed = $config['options'] ?? [];
        $denied = $config['deny'] ?? [];
        $overrides = $this->overrideRepository->findByDownloaderIndexedByOption($downloader);

        $exposed = [];
        foreach ($options as $key => $option) {
            if ($this->isHardDenied($downloader, $option)) {
                continue;
            }

            $override = $overrides[$key] ?? null;
            $isExposed = $override?->isExposed()
                ?? ((in_array($option->group, $groups, true) || in_array($key, $allowed, true)) && !in_array($key, $denied, true));
            if (!$isExposed) {
                continue;
            }

            $exposed[$key] = $option->withOverrides(
                $override?->getLabel() ?? $config['overrides'][$key]['label'] ?? null,
                $override?->getType() ?? $config['overrides'][$key]['type'] ?? null,
            );
        }

        return $exposed;
    }
}
