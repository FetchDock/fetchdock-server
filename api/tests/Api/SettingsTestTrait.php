<?php

namespace App\Tests\Api;

use App\Entity\Client;
use App\Entity\UserDownloaderSetting;
use App\Entity\UserSetting;
use App\Entity\UserSiteSetting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes all user settings layers and clients, so each test starts with defaults only.
 */
trait SettingsTestTrait
{
    private function resetSettings(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([UserSetting::class, UserDownloaderSetting::class, UserSiteSetting::class, Client::class] as $class) {
            $entityManager->createQuery(sprintf('DELETE FROM %s e', $class))->execute();
        }
        $entityManager->clear();
    }
}
