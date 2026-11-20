<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\ExtractorReferenceDTO;
use App\Dto\RequestContextDTO;
use App\Dto\SiteReferenceDTO;
use App\Model\EffectiveSettings;
use App\State\ResolveSiteProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Which site a URL belongs to, which downloaders can handle it, and the settings that apply.
 * Unsupported URLs resolve with `site: null` and no extractors (not a 404).
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/sites/resolve',
            description: 'Resolves a URL to its site, the downloaders that support it and the effective settings.',
            security: "is_granted('ROLE_USER')",
            provider: ResolveSiteProvider::class,
            parameters: [
                'url' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
                    description: 'The http(s) URL to resolve',
                    required: true,
                    constraints: [
                        new Assert\NotBlank(),
                        new Assert\Length(max: 2048),
                        new Assert\Url(protocols: ['http', 'https'], requireTld: true),
                    ],
                ),
            ],
            normalizationContext: ['preserve_empty_objects' => true, 'skip_null_values' => false],
        ),
    ],
)]
final class SiteResolution
{
    #[ApiProperty(identifier: true)]
    public string $url;

    public string $host;

    #[ApiProperty(genId: false)]
    public ?SiteReferenceDTO $site = null;

    /**
     * @var list<ExtractorReferenceDTO>
     */
    #[ApiProperty(genId: false)]
    public array $extractors = [];

    #[ApiProperty(genId: false)]
    public EffectiveSettings $effectiveSettings;

    #[ApiProperty(genId: false)]
    public RequestContextDTO $requestContext;
}
