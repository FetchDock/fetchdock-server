<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use Symfony\Component\HttpFoundation\Request;

/**
 * The fields a settings write applies: all of them for PUT (full replace, omitted = null = inherit),
 * only the ones present in the body for PATCH (JSON merge patch).
 *
 * The settings resources are deserialized into a fresh object, so a PATCH can't rely on the
 * provided object being populated.
 */
final class PatchedFields
{
    /**
     * @return list<string>|null null for all fields
     */
    public static function fromContext(Operation $operation, array $context): ?array
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof Patch || !$request instanceof Request) {
            return null;
        }

        return array_values(array_map('strval', array_keys($request->toArray())));
    }
}
