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

        $subjects = [];
        $objectManager->expects($this->exactly(2))
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$subjects) {
                $this->assertInstanceOf(OidcSubjectIdentifier::class, $entity);
                $subjects[] = $entity->getSubject();
            });

        $objectManager->expects($this->once())
            ->method('flush');

        $fixtures = new TestUserFixtures();
        $fixtures->load($objectManager);

        // One subject identifier per in-memory test user
        $this->assertSame(['admin', 'user'], $subjects);
    }
}
