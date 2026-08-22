<?php

namespace App\DataFixtures;

use App\Entity\OidcSubjectIdentifier;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class TestUserFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $oidcSubjectIdentifier = new OidcSubjectIdentifier();
        $oidcSubjectIdentifier->setSubject('admin');
        $manager->persist($oidcSubjectIdentifier);

        $manager->flush();
    }
}
