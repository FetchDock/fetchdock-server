<?php

namespace App\Model;

/**
 * The media identity the downloader tools derive from a URL, as reported by `bin/resolve_url.py canonical`.
 * Both parts are null when no extractor recognises the URL.
 */
final readonly class CanonicalExtractorMatch
{
    /**
     * @param list<string> $galleryDlGroups URL pattern groups of the gallery-dl extractor, unset groups as ""
     */
    public function __construct(
        /** yt-dlp extractor key, e.g. "Youtube" */
        public ?string $ytDlpExtractor = null,
        /** ID yt-dlp's extractor reads from the URL, e.g. "dQw4w9WgXcQ" */
        public ?string $ytDlpId = null,
        /** gallery-dl category, e.g. "twitter" */
        public ?string $galleryDlCategory = null,
        /** gallery-dl subcategory, e.g. "tweet" */
        public ?string $galleryDlSubcategory = null,
        public array $galleryDlGroups = [],
    ) {
    }

    public function hasYtDlp(): bool
    {
        return null !== $this->ytDlpExtractor && null !== $this->ytDlpId && '' !== $this->ytDlpId;
    }

    public function hasGalleryDl(): bool
    {
        return null !== $this->galleryDlCategory && null !== $this->galleryDlSubcategory;
    }

    /**
     * @param array{ytdlp?: mixed, gdl?: mixed} $output one result of the helper
     */
    public static function fromHelperOutput(array $output): self
    {
        $ytDlp = is_array($output['ytdlp'] ?? null) ? $output['ytdlp'] : [];
        $galleryDl = is_array($output['gdl'] ?? null) ? $output['gdl'] : [];

        return new self(
            is_string($ytDlp['ie'] ?? null) ? $ytDlp['ie'] : null,
            is_string($ytDlp['id'] ?? null) ? $ytDlp['id'] : null,
            is_string($galleryDl['category'] ?? null) ? $galleryDl['category'] : null,
            is_string($galleryDl['subcategory'] ?? null) ? $galleryDl['subcategory'] : null,
            array_values(array_map(strval(...), array_filter($galleryDl['groups'] ?? [], is_scalar(...)))),
        );
    }
}
