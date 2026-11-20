<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\ClientPreference;
use App\Repository\ClientPreferenceRepository;
use App\Resource\Settings\ClientPreferences;
use App\Security\CurrentOwner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Stores the request's `data` as sent: the body is decoded here (the operation doesn't deserialize),
 * so empty objects and arrays keep their JSON type.
 *
 * @implements ProcessorInterface<ClientPreferences, ClientPreferences|null>
 */
final readonly class ClientPreferencesProcessor implements ProcessorInterface
{
    private const int MAX_DEPTH = 32;

    public function __construct(
        private CurrentOwner $currentOwner,
        private ClientPreferenceRepository $clientPreferenceRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ClientPreferences
    {
        $owner = $this->currentOwner->get();
        $clientType = (string) $uriVariables['clientType'];
        $preference = $this->clientPreferenceRepository->findOneBy(['owner' => $owner, 'clientType' => $clientType]);

        if ($operation instanceof DeleteOperationInterface) {
            if (null !== $preference) {
                $this->entityManager->remove($preference);
                $this->entityManager->flush();
            }

            return null;
        }

        $preference ??= new ClientPreference()->setOwner($owner)->setClientType($clientType);
        $preference->setData($this->decodeData($context['request'] ?? null, $data));
        $this->entityManager->persist($preference);
        $this->entityManager->flush();

        return ClientPreferences::fromEntity($clientType, $preference);
    }

    /**
     * @return string the `data` object of the request body, JSON encoded
     */
    private function decodeData(?Request $request, ClientPreferences $resource): string
    {
        $body = (string) $request?->getContent();
        if (strlen($body) > ClientPreference::MAX_BODY_BYTES) {
            throw new HttpException(413, sprintf('The request body must not exceed %d bytes.', ClientPreference::MAX_BODY_BYTES));
        }

        try {
            $decoded = json_decode($body, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('The request body must be valid JSON (at most '.self::MAX_DEPTH.' levels deep).', $e);
        }

        if (!$decoded instanceof \stdClass || !property_exists($decoded, 'data') || !$decoded->data instanceof \stdClass) {
            $message = 'This value should be of type object.';
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, $message, [], $resource, 'data', $decoded->data ?? null)]));
        }

        return json_encode($decoded->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
