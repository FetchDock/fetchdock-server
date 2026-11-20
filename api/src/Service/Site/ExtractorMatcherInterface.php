<?php

namespace App\Service\Site;

use App\Model\ExtractorMatch;

interface ExtractorMatcherInterface
{
    /**
     * Offline lookup (no network access) of the extractors that can handle the URL, retrying with the
     * www. and parent-domain variants of its host when the URL itself matches nothing.
     *
     * @return list<ExtractorMatch>
     */
    public function match(string $url): array;

    /**
     * Network fallback for URLs match() found nothing for: fetches the page with yt-dlp's generic
     * extractor. Non-http(s) URLs and hosts resolving to non-public addresses are never fetched.
     * A supported page yields one "Generic:<registrable domain>" match, with that domain as family.
     *
     * @return list<ExtractorMatch>
     */
    public function probe(string $url): array;

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
