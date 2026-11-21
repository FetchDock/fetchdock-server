<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\State\Admin\AdminCanonicalizationProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The canonical key a URL gets (duplicate detection, #94), for debugging canonicalization rules.
 */
#[ApiResource(
    shortName: 'AdminCanonicalization',
    operations: [
        new Get(
            uriTemplate: '/admin/canonicalize',
            description: 'The canonical key a submitted URL would get, with the layer and profile that produced it.',
            provider: AdminCanonicalizationProvider::class,
            parameters: [
                'url' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
                    description: 'The http(s) URL to canonicalize',
                    required: true,
                    constraints: [
                        new Assert\NotBlank(),
                        new Assert\Length(max: 2048),
                        new Assert\Url(protocols: ['http', 'https'], requireTld: true),
                    ],
                ),
            ],
            normalizationContext: ['skip_null_values' => false],
        ),
    ],
    security: "is_granted('ROLE_ADMIN')",
)]
final class AdminCanonicalization
{
    #[ApiProperty(identifier: true)]
    public string $url;

    /** e.g. "ytdlp:Youtube:dQw4w9WgXcQ", "gdl:imgur:gallery:abcde", "url:https://example.com/a" */
    public string $key;

    /** Layer that produced the key: "ytdlp", "gdl" or "url" */
    #[ApiProperty(openapiContext: ['enum' => ['ytdlp', 'gdl', 'url']])]
    public string $layer;

    /** Name of the canonicalization profile used */
    public string $profile;

    /** Slug of the site the URL resolves to, null if none */
    public ?string $site = null;
}
