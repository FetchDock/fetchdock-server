<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\OidcSubjectIdentifier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * The owner's client with the given id; null for malformed, unknown or other users' ids.
     */
    public function findOneByIdAndOwner(string $id, OidcSubjectIdentifier $owner): ?Client
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }
}
