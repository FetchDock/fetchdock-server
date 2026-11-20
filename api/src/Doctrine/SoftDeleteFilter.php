<?php

namespace App\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs code with the gedmo soft-delete filter disabled, e.g. for admin views of deleted records.
 *
 * Build the output inside the callback: lazy-loaded relations are filtered again once it returns.
 */
final readonly class SoftDeleteFilter
{
    public const string NAME = 'softdeleteable';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function disabled(callable $callback): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled(self::NAME);
        if ($wasEnabled) {
            $filters->disable(self::NAME);
        }

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $filters->enable(self::NAME);
            }
        }
    }
}
