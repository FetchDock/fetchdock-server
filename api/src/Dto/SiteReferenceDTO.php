<?php

namespace App\Dto;

final readonly class SiteReferenceDTO
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }
}
