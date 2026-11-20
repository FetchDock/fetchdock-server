<?php

namespace App\Service\Site;

use App\Model\ExtractorMatch;

interface ExtractorMatcherInterface
{
    /**
     * Offline lookup (no network access) of the extractors that can handle the URL.
     *
     * @return list<ExtractorMatch>
     */
    public function match(string $url): array;

    /**
     * All extractors of the given families, with the hosts the tools know for them (from extractor
     * roots, examples and tests: noisy, filter before use).
     *
     * @param list<string> $families
     *
     * @return list<array{match: ExtractorMatch, hosts: list<string>}>
     */
    public function catalog(array $families): array;
}
