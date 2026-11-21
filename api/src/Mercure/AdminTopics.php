<?php

namespace App\Mercure;

use Symfony\Component\Mercure\HubInterface;

/**
 * Mercure topics for the /admin/... resources (#55), for subscribers with ROLE_VIEW_ALL_DOWNLOADS.
 *
 * The API Platform admin subscribes to `new URL(record IRI, topicUrl)`, i.e. absolute URLs. Admin topics are therefore
 * absolute too, based on the origin of the hub's public URL: the admin gets the same origin from the subscription token
 * response (hubUrl) and uses it as its topicUrl, so both sides agree even where no request is available (the worker).
 */
final readonly class AdminTopics
{
    public function __construct(private HubInterface $hub)
    {
    }

    /**
     * Scheme, host and (non-default) port of the hub's public URL, e.g. "https://localhost".
     */
    public function base(): string
    {
        $url = parse_url($this->hub->getPublicUrl());
        $scheme = strtolower($url['scheme'] ?? 'https');
        $port = $url['port'] ?? null;
        $defaultPort = ['http' => 80, 'https' => 443][$scheme] ?? null;

        return $scheme.'://'.strtolower($url['host'] ?? 'localhost').(null !== $port && $port !== $defaultPort ? ':'.$port : '');
    }

    /**
     * URI template matching every admin topic, for the "mercure.subscribe" claim of an admin's subscriber token.
     */
    public function subscribeSelector(): string
    {
        return $this->base().'/admin/{+path}';
    }

    /**
     * Topic of a download job's admin updates (the AdminDownloadJob resource's IRI, absolute).
     */
    public function downloadJob(string $uuid): string
    {
        return $this->base().'/admin/download_jobs/'.$uuid;
    }
}
