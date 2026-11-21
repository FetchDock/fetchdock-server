<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The canonicalization settings of an AdminSiteCanonicalization are valid for CanonicalProfileRegistry:
 * known profile, valid field types and idGroups.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCanonicalization extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
