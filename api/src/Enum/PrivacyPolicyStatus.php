<?php

namespace App\Enum;

/**
 * Which privacy policy the instance publishes (ADR 0013).
 */
enum PrivacyPolicyStatus: string
{
    /** The generated policy, at /privacy */
    case GENERATED = 'generated';
    /** The operator's own policy (PRIVACY_POLICY_URL) */
    case CUSTOM = 'custom';
    /** Disabled by the operator (PRIVACY_POLICY_ENABLED=0), e.g. for personal/household instances */
    case DISABLED = 'disabled';
    /** None: see PrivacyPolicyUnavailableReason */
    case UNAVAILABLE = 'unavailable';
}
