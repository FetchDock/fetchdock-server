<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use App\Doctrine\SoftDeleteFilter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paginates an admin query with the soft-delete filter disabled, mapping each result to its output
 * while the filter is still disabled (so lazy-loaded relations include soft-deleted records too).
 */
final readonly class AdminPaginator
{
    public function __construct(
        #[Autowire(service: 'api_platform.pagination')]
        private Pagination $pagination,
        private SoftDeleteFilter $softDeleteFilter,
    ) {
    }

    /**
     * @template T of object
     *
     * @param callable(list<object>): list<T> $map maps one page of results at once (allows batched lookups)
     *
     * @return TraversablePaginator<T>
     */
    public function paginate(QueryBuilder $queryBuilder, Operation $operation, array $context, callable $map): TraversablePaginator
    {
        $page = $this->pagination->getPage($context);
        $limit = $this->pagination->getLimit($operation, $context);

        return $this->softDeleteFilter->disabled(function () use ($queryBuilder, $page, $limit, $map): TraversablePaginator {
            $queryBuilder->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
            $paginator = new Paginator($queryBuilder->getQuery(), fetchJoinCollection: true);
            $items = $map(iterator_to_array($paginator->getIterator(), false));

            return new TraversablePaginator(new \ArrayIterator($items), $page, $limit, count($paginator));
        });
    }

    /**
     * Boolean query parameter: true for "true"/"1", false for "false"/"0", null if omitted.
     */
    public static function boolFilter(array $context, string $name): ?bool
    {
        $value = $context['filters'][$name] ?? null;

        return match ($value) {
            'true', '1', true => true,
            'false', '0', false => false,
            default => null,
        };
    }

    public static function stringFilter(array $context, string $name): ?string
    {
        $value = $context['filters'][$name] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }
}
