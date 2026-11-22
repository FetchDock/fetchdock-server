<?php

namespace App\Controller;

use App\Enum\PrivacyPolicyStatus;
use App\Enum\PrivacyPolicyUnavailableReason;
use App\Repository\PrivacyPolicyRevisionRepository;
use App\Service\Privacy\PrivacyPolicyPublisher;
use App\Service\Privacy\PrivacyPolicyRevisions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The instance's privacy policy as a web page (ADR 0013). Public; not an API Platform resource because it serves
 * HTML. Clients read the status from GET /privacy_policy.
 */
final class PrivacyPolicyController extends AbstractController
{
    /** The page only styles itself: no scripts, no external resources */
    private const string CONTENT_SECURITY_POLICY = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    public function __construct(
        private readonly PrivacyPolicyPublisher $publisher,
        private readonly PrivacyPolicyRevisionRepository $revisionRepository,
        private readonly PrivacyPolicyRevisions $revisions,
    ) {
    }

    /**
     * The current generated policy, a redirect to the operator's own policy, or 404 with the reason. ?revision=N
     * shows an earlier revision of the generated policy, whatever the current status.
     */
    #[Route('/privacy', name: PrivacyPolicyPublisher::ROUTE, methods: ['GET', 'HEAD'])]
    public function show(Request $request): Response
    {
        [$status, $reason] = $this->publisher->resolve();
        $current = $this->publisher->currentRevision($status);

        if ($request->query->has('revision')) {
            $number = $request->query->getString('revision');
            $revision = ctype_digit($number) ? $this->revisionRepository->findOneByNumber((int) $number) : null;
            if (null === $revision) {
                return $this->page('privacy_page/unavailable.html.twig', ['revisionNotFound' => true, 'disabled' => false, 'reason' => null], Response::HTTP_NOT_FOUND, 60);
            }

            $isCurrent = $revision->getNumber() === $current?->getNumber();

            return $this->page('privacy_page/policy.html.twig', [
                'revision' => $revision,
                'isCurrent' => $isCurrent,
                'outdated' => $isCurrent && $this->publisher->isOutdated($status),
                'currentUrl' => $this->publisher->url($status),
                'revisions' => $this->revisionRepository->findSummariesNewestFirst(),
                'preview' => false,
            ], Response::HTTP_OK, 86400);
        }

        if (PrivacyPolicyStatus::CUSTOM === $status) {
            return new RedirectResponse((string) $this->publisher->url($status));
        }
        if (PrivacyPolicyStatus::DISABLED === $status) {
            return $this->page('privacy_page/unavailable.html.twig', [
                'revisionNotFound' => false,
                'disabled' => true,
                'reason' => null,
            ], Response::HTTP_NOT_FOUND, 300);
        }
        if (null === $current) {
            return $this->page('privacy_page/unavailable.html.twig', [
                'revisionNotFound' => false,
                'disabled' => false,
                'reason' => $reason ?? PrivacyPolicyUnavailableReason::INCOMPLETE,
            ], Response::HTTP_NOT_FOUND, 60);
        }

        return $this->page('privacy_page/policy.html.twig', [
            'revision' => $current,
            'isCurrent' => true,
            'outdated' => $this->publisher->isOutdated($status),
            'currentUrl' => null,
            'revisions' => $this->revisionRepository->findSummariesNewestFirst(),
            'preview' => false,
        ], Response::HTTP_OK, 300);
    }

    /**
     * The policy rendered from the saved answers without publishing it, also while it is unavailable.
     */
    #[Route('/admin/privacy_policy/preview', name: 'app_admin_privacy_policy_preview', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function preview(): Response
    {
        $response = $this->render('privacy_page/policy.html.twig', [
            'revision' => null,
            'html' => $this->revisions->preview()->html,
            'isCurrent' => false,
            'outdated' => false,
            'currentUrl' => null,
            'revisions' => [],
            'preview' => true,
        ]);
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, array $parameters, int $status, int $maxAge): Response
    {
        $response = $this->render($template, $parameters + ['html' => null], new Response(status: $status));
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $response->setPublic();
        $response->setMaxAge($maxAge);

        return $response;
    }
}
