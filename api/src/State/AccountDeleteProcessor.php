<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Resource\CurrentUser;
use App\Security\CurrentOwner;
use App\Service\Account\AccountDeleter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * DELETE /users/me?confirm={subject}: the user deletes their own account (server#129).
 *
 * @implements ProcessorInterface<CurrentUser|null, void>
 */
final readonly class AccountDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private CurrentOwner $currentOwner,
        private AccountDeleter $accountDeleter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        // Guards against accidental calls: the client must name the account it deletes
        // The operation requires ROLE_USER, so there is a user
        if ($context['request']?->query->get('confirm') !== $this->security->getUser()->getUserIdentifier()) {
            throw new BadRequestHttpException('The "confirm" parameter must be your subject (see GET /users/me).');
        }

        // A user the server doesn't know yet has nothing to delete
        $owner = $this->currentOwner->find();
        if (null !== $owner) {
            $this->accountDeleter->delete($owner);
        }
    }
}
