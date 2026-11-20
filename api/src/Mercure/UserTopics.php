<?php

namespace App\Mercure;

use App\Entity\OidcSubjectIdentifier;
use App\Model\DownloadJobInterface;

/**
 * Mercure topics for user-related updates.
 *
 * All of a user's updates are published privately under one prefix, "/users/{user uuid}". A subscriber token for that
 * user covers exactly this prefix (see subscribeSelector()), so a user can never receive another user's updates.
 *
 * The prefix followed by a resource IRI is the resource's topic, e.g. "/users/{uuid}/download_jobs/{job uuid}", so
 * clients such as the API Platform admin can use the prefix as their topic base.
 */
final class UserTopics
{
    public static function prefix(OidcSubjectIdentifier $user): string
    {
        return '/users/'.$user->getUuid()->toRfc4122();
    }

    /**
     * URI template matching every topic of the user, for the "mercure.subscribe" claim of their subscriber token.
     */
    public static function subscribeSelector(OidcSubjectIdentifier $user): string
    {
        return self::prefix($user).'/{+path}';
    }

    /**
     * Topic of a download job's own updates, or null if the job has no owner (no topic can be built).
     */
    public static function downloadJob(DownloadJobInterface $downloadJob): ?string
    {
        $owner = $downloadJob->getOwner();
        $uuid = $downloadJob->getUuid();
        if (null === $owner || null === $uuid) {
            return null;
        }

        return self::prefix($owner).'/download_jobs/'.$uuid->toRfc4122();
    }

    /**
     * Topic of a download job's progress events (state, progress, errors), or null if the job has no owner.
     */
    public static function downloadJobOutput(DownloadJobInterface $downloadJob): ?string
    {
        $topic = self::downloadJob($downloadJob);

        return null === $topic ? null : $topic.'/output';
    }
}
