<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Interface\OwnerFilterableInterface;
use App\Repository\OidcSubjectIdentifierRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private Security $security,
        private OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository
    )
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    public function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $securityUser = $this->security->getUser();
        if (null !== $securityUser) {
            $oidcUser = $this->oidcSubjectIdentifierRepository->findOneBy(['subject' => $securityUser->getUserIdentifier()]);

            // Check if the $resourceClass implements the OwnerFilterableInterface
            if (!in_array(OwnerFilterableInterface::class, class_implements($resourceClass), true)) {
                return;
            }

            /** @var OwnerFilterableInterface $resourceClass */
            $resourceClass::getOwnerQueryBuilder($queryBuilder, $oidcUser->getId());
        }
    }
}
