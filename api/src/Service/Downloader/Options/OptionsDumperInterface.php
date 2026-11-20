<?php

namespace App\Service\Downloader\Options;

/**
 * Lists the command line options of a downloader tool (see bin/dump_options.py).
 */
interface OptionsDumperInterface
{
    /**
     * @param string $tool "gallery-dl" or "yt-dlp"
     *
     * @return array{version: string, options: list<array{flags: list<string>, dest: ?string, kind: string, type: ?string, const: mixed, metavar: ?string, default: mixed, help: ?string, group: string, choices: ?list<mixed>, hidden: bool}>}
     *
     * @throws \RuntimeException when the options can't be listed
     */
    public function dump(string $tool): array;
}
