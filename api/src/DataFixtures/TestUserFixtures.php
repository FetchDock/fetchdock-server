<?php

namespace App\DataFixtures;

use App\Entity\OidcSubjectIdentifier;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class TestUserFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // One subject identifier per in-memory test user (see security.yaml, when@test)
        foreach (['admin', 'user'] as $subject) {
            $oidcSubjectIdentifier = new OidcSubjectIdentifier();
            $oidcSubjectIdentifier->setSubject($subject);
            $manager->persist($oidcSubjectIdentifier);
        }

        $manager->flush();
    }
}
