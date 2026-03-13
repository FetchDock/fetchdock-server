<?php

namespace App\Tests\Unit\DataFixtures;

use App\DataFixtures\TestUserFixtures;
use App\Entity\OidcSubjectIdentifier;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

class TestUserFixturesTest extends TestCase
{
    public function testLoad()
    {
        $objectManager = $this->createMock(ObjectManager::class);

        $objectManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(OidcSubjectIdentifier::class));

        $objectManager->expects($this->once())
            ->method('flush');

        $fixtures = new TestUserFixtures();
        $fixtures->load($objectManager);
    }
}
