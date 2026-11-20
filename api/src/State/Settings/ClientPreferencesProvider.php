<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\ClientPreferenceRepository;
use App\Resource\Settings\ClientPreferences;
use App\Security\CurrentOwner;

/**
 * @implements ProviderInterface<ClientPreferences>
 */
final readonly class ClientPreferencesProvider implements ProviderInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private ClientPreferenceRepository $clientPreferenceRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ClientPreferences
    {
        $clientType = (string) $uriVariables['clientType'];

        return ClientPreferences::fromEntity(
            $clientType,
            $this->clientPreferenceRepository->findOneBy(['owner' => $this->currentOwner->get(), 'clientType' => $clientType]),
        );
    }
}
