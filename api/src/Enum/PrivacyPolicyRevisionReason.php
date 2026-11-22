<?php

namespace App\Enum;

/**
 * Why a privacy policy revision was created (ADR 0013).
 */
enum PrivacyPolicyRevisionReason: string
{
    /** The first revision */
    case INITIAL = 'initial';
    /** The operator changed their answers */
    case ANSWERS = 'answers';
    /** The FetchDock version or the policy text changed */
    case APP_VERSION = 'app_version';
    /** The runtime configuration changed the text (failure reports, site defaults, roles in use, ...) */
    case CONFIGURATION = 'configuration';
}
