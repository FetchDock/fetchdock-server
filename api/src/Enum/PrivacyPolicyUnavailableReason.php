<?php

namespace App\Enum;

/**
 * Why the instance publishes no privacy policy (ADR 0013). The integrity reasons come first: completing the answers
 * doesn't help while the code can't be verified.
 */
enum PrivacyPolicyUnavailableReason: string
{
    /** The integrity check found files that differ from the official build */
    case INTEGRITY_MODIFIED = 'integrity_modified';
    /** The integrity check couldn't verify the code (offline, a fork, a development build, or no recent check) */
    case INTEGRITY_UNKNOWN = 'integrity_unknown';
    /** The operator hasn't answered every required question */
    case INCOMPLETE = 'incomplete';
    /** Required answers are complete, but an administrator hasn't reviewed and published the first revision yet */
    case NOT_PUBLISHED = 'not_published';
}
