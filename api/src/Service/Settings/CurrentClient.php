<?php

namespace App\Service\Settings;

use App\Entity\Client;
use App\Repository\ClientRepository;
use App\Security\CurrentOwner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The registered client of the current request, from the X-Client-Id header.
 *
 * Unknown, malformed or other users' ids are ignored (no client settings layer), never an error.
 */
class CurrentClient implements ResetInterface
{
    public const string HEADER = 'X-Client-Id';
    /** lastSeenAt is written at most once per this many seconds per client */
    public const int LAST_SEEN_THROTTLE = 300;

    private bool $resolved = false;
    private ?Client $client = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CurrentOwner $currentOwner,
        private readonly ClientRepository $clientRepository,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(): ?Client
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->client = $this->resolve();
        }

        return $this->client;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->client = null;
    }

    private function resolve(): ?Client
    {
        $id = $this->requestStack->getMainRequest()?->headers->get(self::HEADER);
        if (null === $id) {
            return null;
        }

        $owner = $this->currentOwner->find();
        if (null === $owner) {
            return null;
        }

        $client = $this->clientRepository->findOneByIdAndOwner($id, $owner);
        if (null === $client) {
            $this->logger->info('Ignoring unknown X-Client-Id', ['client_id' => $id]);

            return null;
        }

        $this->touch($client);

        return $client;
    }

    /**
     * Updates lastSeenAt directly in the database, so it doesn't depend on (or trigger) a flush.
     */
    private function touch(Client $client): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        try {
            $this->clientRepository->createQueryBuilder('c')
                ->update()
                ->set('c.lastSeenAt', ':now')
                ->where('c.id = :id')
                ->andWhere('c.lastSeenAt IS NULL OR c.lastSeenAt < :threshold')
                ->setParameter('now', $now, 'datetime_immutable')
                ->setParameter('threshold', $now->modify(sprintf('-%d seconds', self::LAST_SEEN_THROTTLE)), 'datetime_immutable')
                ->setParameter('id', $client->getId(), UuidType::NAME)
                ->getQuery()
                ->execute();
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to update client lastSeenAt', ['client_id' => $client->getId()->toRfc4122(), 'exception' => $e]);
        }
    }
}
