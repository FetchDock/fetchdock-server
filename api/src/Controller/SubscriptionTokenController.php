<?php

namespace App\Controller;

use App\Mercure\AdminTopics;
use App\Mercure\UserTopics;
use App\Repository\OidcSubjectIdentifierRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issues short-lived Mercure subscriber tokens to authenticated clients.
 *
 * A token only allows subscribing to the current user's own topics (see UserTopics), plus the admin topics for users
 * with ROLE_VIEW_ALL_DOWNLOADS (see AdminTopics), and carries no publish rights.
 * Clients present it to the hub as the "authorization" query parameter, an "Authorization: Bearer" header, or a cookie.
 */
final class SubscriptionTokenController extends AbstractController
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository,
        private readonly AdminTopics $adminTopics,
        #[Autowire(param: 'app.mercure.subscription_token_ttl')]
        private readonly int $tokenTtl,
    ) {
    }

    #[Route('/users/me/subscription-token', name: 'app_users_me_subscription_token', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $securityUser = $this->getUser();
        $user = null === $securityUser ? null : $this->oidcSubjectIdentifierRepository->findOneBy(['subject' => $securityUser->getUserIdentifier()]);
        if (null === $user) {
            throw new AccessDeniedHttpException('No user found for the authenticated subject.');
        }

        $factory = $this->hub->getFactory() ?? throw new \LogicException('The Mercure hub has no token factory configured.');
        $topics = [UserTopics::subscribeSelector($user)];
        if ($this->isGranted('ROLE_VIEW_ALL_DOWNLOADS')) {
            // All users' download jobs, as the /admin/... resources (see AdminTopics)
            $topics[] = $this->adminTopics->subscribeSelector();
        }
        $expiresAt = new \DateTimeImmutable("+{$this->tokenTtl} seconds");

        // Subscribe to the user's own topics only; null for publish adds no publish claim at all
        $token = $factory->create($topics, null, ['exp' => $expiresAt]);

        $response = new JsonResponse([
            'hubUrl' => $this->hub->getPublicUrl(),
            'token' => $token,
            'topics' => $topics,
            'expiresAt' => $expiresAt->format(\DATE_ATOM),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
