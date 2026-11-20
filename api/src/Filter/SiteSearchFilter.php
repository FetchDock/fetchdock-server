<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * ?search=… matches a site's name or one of its domains (case-insensitive, substring).
 */
final class SiteSearchFilter extends AbstractFilter
{
    private const string PARAMETER = 'search';

    protected function filterProperty(string $property, mixed $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (self::PARAMETER !== $property || !is_string($value) || '' === trim($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName(self::PARAMETER);
        $pattern = '%'.addcslashes(mb_strtolower(trim($value)), '%_\\').'%';

        $queryBuilder
            ->andWhere(sprintf('LOWER(%1$s.name) LIKE :%2$s OR LOWER(JSONB_TEXT(%1$s.domains)) LIKE :%2$s', $alias, $parameter))
            ->setParameter($parameter, $pattern);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'description' => 'Case-insensitive search in the site name and domains.',
                'openapi' => ['example' => 'twitter'],
            ],
        ];
    }
}
