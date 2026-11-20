<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ClientSetting;
use App\Repository\ClientRepository;
use App\Resource\Settings\ClientSettings;
use App\Security\CurrentOwner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ClientSettings, ClientSettings|null>
 */
final readonly class ClientSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private ClientRepository $clientRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ClientSettings
    {
        $client = $this->clientRepository->findOneByIdAndOwner((string) $uriVariables['clientId'], $this->currentOwner->get())
            ?? throw new NotFoundHttpException('Client not found.');
        $setting = $client->getSetting();

        if ($operation instanceof DeleteOperationInterface) {
            if (null !== $setting) {
                $client->setSetting(null);
                $this->entityManager->remove($setting);
                $this->entityManager->flush();
            }

            return null;
        }

        if (null === $setting) {
            $setting = new ClientSetting();
            $client->setSetting($setting);
        }
        $this->entityManager->persist($data->applyTo($setting, PatchedFields::fromContext($operation, $context)));
        $this->entityManager->flush();

        return ClientSettings::fromEntity($client->getId()->toRfc4122(), $setting);
    }
}
