<?php

namespace App\Resource\Settings;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Entity\ClientPreference;
use App\State\Settings\ClientPreferencesProcessor;
use App\State\Settings\ClientPreferencesProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Opaque preferences shared by all of the user's clients of one type (e.g. theme, shortcuts of the
 * browser extension). The server stores `data` as sent and never interprets it.
 */
#[ApiResource(
    shortName: 'ClientPreferences',
    operations: [
        new Get(
            uriTemplate: '/users/client-preferences/{clientType}',
            description: 'The preferences of the client type; `data` is {} and `updatedAt` null if none were stored.',
        ),
        new Put(
            uriTemplate: '/users/client-preferences/{clientType}',
            description: 'Replaces the preferences of the client type. `data` must be a JSON object; the request body is limited to 16 KB (413).',
            deserialize: false,
            extraProperties: ['standard_put' => true],
        ),
        new Delete(uriTemplate: '/users/client-preferences/{clientType}', description: 'Removes the preferences of the client type.'),
    ],
    requirements: ['clientType' => '[a-z0-9-]{1,32}'],
    normalizationContext: ['groups' => ['client_preferences:read'], 'skip_null_values' => false, 'preserve_empty_objects' => true],
    denormalizationContext: ['groups' => ['client_preferences:write']],
    security: "is_granted('ROLE_USER')",
    provider: ClientPreferencesProvider::class,
    processor: ClientPreferencesProcessor::class,
)]
final class ClientPreferences
{
    /** Kind of client (see Client.type), e.g. "browser-extension" */
    #[ApiProperty(identifier: true)]
    #[Groups(['client_preferences:read'])]
    public string $clientType;

    /** Opaque JSON object, at most 16 KB per request */
    #[ApiProperty(openapiContext: ['type' => 'object', 'additionalProperties' => true])]
    #[Groups(['client_preferences:read', 'client_preferences:write'])]
    public \ArrayObject $data;

    /** When the preferences were last replaced, null if none were stored */
    #[Groups(['client_preferences:read'])]
    public ?\DateTimeImmutable $updatedAt = null;

    public static function fromEntity(string $clientType, ?ClientPreference $preference): self
    {
        $preferences = new self();
        $preferences->clientType = $clientType;
        $preferences->data = self::toArrayObject(json_decode($preference?->getData() ?? '{}', false, 512, JSON_THROW_ON_ERROR));
        $preferences->updatedAt = $preference?->getUpdatedAt();

        return $preferences;
    }

    /**
     * JSON objects become ArrayObjects (serialized as objects, also when empty), lists stay arrays.
     */
    private static function toArrayObject(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return new \ArrayObject(array_map(self::toArrayObject(...), get_object_vars($value)));
        }

        return is_array($value) ? array_map(self::toArrayObject(...), $value) : $value;
    }
}
