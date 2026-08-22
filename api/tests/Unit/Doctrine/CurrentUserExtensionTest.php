<?php

namespace App\Tests\Unit\Doctrine;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\GraphQl\Operation;
use App\Doctrine\CurrentUserExtension;
use App\Entity\OidcSubjectIdentifier;
use App\Interface\OwnerFilterableInterface;
use App\Repository\OidcSubjectIdentifierRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class CurrentUserExtensionTest extends TestCase
{
    private CurrentUserExtension $currentUserExtension;

    private Security $security;

    private OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository;

    private QueryNameGeneratorInterface $queryNameGenerator;

    private Operation $operation;

    private QueryBuilder $queryBuilder;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->oidcSubjectIdentifierRepository = $this->createMock(OidcSubjectIdentifierRepository::class);

        $this->queryNameGenerator = $this->createMock(QueryNameGeneratorInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);

        $this->currentUserExtension = new CurrentUserExtension($this->security, $this->oidcSubjectIdentifierRepository);
    }

    public function testOwnerIsAddedToQueryIfSecurityReturnsAValidUser()
    {
        $this->setAddWhereAssertions('a', true);
        $this->setQueryBuilderAssertions('a');

        $this->currentUserExtension->addWhere($this->queryBuilder, OwnerFilterableTestClass::class);
    }

    public function testApplyToCollection()
    {
        $this->setAddWhereAssertions('a', true);
        $this->setQueryBuilderAssertions('a');

        $this->currentUserExtension->applyToCollection(
            $this->queryBuilder,
            $this->queryNameGenerator,
            OwnerFilterableTestClass::class
        );
    }

    public function testApplyToItem()
    {
        $this->setAddWhereAssertions('a', true);
        $this->setQueryBuilderAssertions('a');

        $this->currentUserExtension->applyToItem(
            $this->queryBuilder,
            $this->queryNameGenerator,
            OwnerFilterableTestClass::class,
            []
        );
    }

    public function testQueryBuilderIsNotCalledWhenClassDoesNotImplementInterface()
    {
        $this->setAddWhereAssertions('a');

        $this->queryBuilder->expects($this->never())
            ->method('andWhere');

        $this->currentUserExtension->addWhere($this->queryBuilder, self::class);
    }

    private function setAddWhereAssertions(string $rootAlias, bool $expectGetIdOnOidc = false): void
    {

        $userIdentifier = 'foo';

        $oidcSubjectIdentifier = $this->createMock(OidcSubjectIdentifier::class);
        if($expectGetIdOnOidc) {
            $oidcSubjectIdentifier->expects($this->atLeastOnce())
                ->method('getId')
                ->willReturn(1);
        }

        $this->oidcSubjectIdentifierRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'subject' => $userIdentifier
            ])
            ->willReturn($oidcSubjectIdentifier);

        $mockUser = $this->createMock(UserInterface::class);

        $this->security->expects($this->once())
            ->method('getUser')
            ->willReturn($mockUser);

        $mockUser->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($userIdentifier);
    }

    private function setQueryBuilderAssertions(string $rootAlias)
    {
        $this->queryBuilder->expects($this->once())
            ->method('getRootAliases')
            ->willReturn([$rootAlias]);

        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('a.owner = :ownerId')
            ->willReturnSelf();

        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('ownerId', 1);
    }
}

class OwnerFilterableTestClass implements OwnerFilterableInterface
{

    public static function getOwnerQueryBuilder(QueryBuilder $queryBuilder, string $ownerIdentifier): QueryBuilder
    {
        // TODO: Implement getOwnerQueryBuilder() method.
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf('%s.owner = :ownerId', $rootAlias))
            ->setParameter('ownerId', $ownerIdentifier);

        return $queryBuilder;
    }
}
