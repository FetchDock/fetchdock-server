<?php

namespace App\Service\Privacy;

final readonly class RenderedPolicy
{
    public function __construct(
        public string $markdown,
        /** An HTML fragment (no document around it) */
        public string $html,
    ) {
    }
}
