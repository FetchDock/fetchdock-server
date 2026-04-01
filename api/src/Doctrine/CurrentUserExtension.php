<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Repository\OidcSubjectIdentifierRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

final class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private Security $security,
        private OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository
    )
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN') && null !== $this->security->getUser()) {
            $oidcUser = $this->oidcSubjectIdentifierRepository->findOneBy(['subject' => $this->security->getUser()->getUserIdentifier()]);

            $rootAlias = $queryBuilder->getRootAliases()[0];
            $queryBuilder->andWhere(sprintf('%s.owner = :user', $rootAlias))
                ->setParameter('user', $oidcUser);
        }
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN') && null !== $this->security->getUser()) {
            $oidcUser = $this->oidcSubjectIdentifierRepository->findOneBy(['subject' => $this->security->getUser()->getUserIdentifier()]);

            $rootAlias = $queryBuilder->getRootAliases()[0];
            $queryBuilder->andWhere(sprintf('%s.owner = :user', $rootAlias))
                ->setParameter('user', $oidcUser);
        }
    }
}
