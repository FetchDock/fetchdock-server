<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\ClientRepository;
use App\Resource\Settings\ClientSettings;
use App\Security\CurrentOwner;

/**
 * @implements ProviderInterface<ClientSettings>
 */
final readonly class ClientSettingsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private ClientRepository $clientRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?ClientSettings
    {
        $client = $this->clientRepository->findOneByIdAndOwner((string) $uriVariables['clientId'], $this->currentOwner->get());

        return null !== $client ? ClientSettings::fromEntity($client->getId()->toRfc4122(), $client->getSetting()) : null;
    }
}
