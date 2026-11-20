<?php

namespace App\Dto;

/**
 * What a client should attach to a download job for this URL.
 */
final readonly class RequestContextDTO
{
    public function __construct(
        /** Send the user's cookies for the site */
        public bool $cookies,
        /** Send the client's user agent */
        public bool $userAgent,
        /** Send the page the URL was found on */
        public bool $referrer,
    ) {
    }
}
