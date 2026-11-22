<?php

namespace App\DataFixtures;

use App\Entity\DownloadDirectory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loading fixtures purges every table, including the default directory the migration seeds: create it again.
 */
class DownloadDirectoryFixtures extends Fixture
{
    public function __construct(#[Autowire(param: 'app.download_dir')] private readonly string $downloadDir)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $manager->persist(new DownloadDirectory('Default', $this->downloadDir, isDefault: true));
        $manager->flush();
    }
}
