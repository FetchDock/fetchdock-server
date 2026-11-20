<?php

namespace App\Security;

use App\Entity\OidcSubjectIdentifier;
use App\Repository\OidcSubjectIdentifierRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The OidcSubjectIdentifier (owner of jobs, settings, clients) of the authenticated user.
 */
class CurrentOwner
{
    public function __construct(
        private readonly Security $security,
        private readonly OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository,
    ) {
    }

    public function find(): ?OidcSubjectIdentifier
    {
        $user = $this->security->getUser();
        if (null === $user) {
            return null;
        }

        return $this->oidcSubjectIdentifierRepository->findOneBy(['subject' => $user->getUserIdentifier()]);
    }

    public function get(): OidcSubjectIdentifier
    {
        return $this->find() ?? throw new AccessDeniedHttpException('No owner for the authenticated user.');
    }
}
