<?php

namespace App\Tests\Integration\Privacy;

use App\Entity\Site;
use App\Entity\SiteSetting;
use App\Model\PrivacyPolicyAnswers;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Service\Privacy\PolicyInputsBuilder;
use App\Service\Privacy\RenderedPolicy;
use App\Tests\Unit\Model\PrivacyPolicyAnswersTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PolicyRendererTest extends KernelTestCase
{
    use PolicyServicesTrait;

    private EntityManagerInterface $entityManager;
    private PrivacyPolicySettingsRepository $settingsRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->settingsRepository = self::getContainer()->get(PrivacyPolicySettingsRepository::class);
        $this->settingsRepository->get()->setViewAllRoleSeenAt(null);
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testRendersTheAnswersVersionsAndInventory(): void
    {
        $policy = $this->render(PrivacyPolicyAnswers::fromArray([
            'operator' => ['name' => 'Alex Example', 'address' => "Street 1\n1234 Town", 'contactEmail' => 'privacy@example.org', 'dpoContact' => 'dpo@example.org'],
            'identityProvider' => ['name' => 'Example ID', 'privacyPolicyUrl' => 'https://id.example.org/privacy'],
            'processors' => [['name' => 'Backup Co', 'purpose' => 'Off-site backups', 'country' => 'DE']],
            'retention' => ['logsDays' => 14, 'backups' => 'Encrypted, kept for 30 days', 'softDeletedJobsDays' => 90, 'reportsDays' => 180],
            'supervisoryAuthority' => 'Autoriteit Persoonsgegevens',
        ] + PrivacyPolicyAnswersTest::complete()), revision: 3);

        self::assertStringStartsWith("# Privacy policy of Family downloads\n", $policy->markdown);
        self::assertStringContainsString('FetchDock 0.9.0, revision 3.', $policy->markdown);
        self::assertStringContainsString('is Alex Example, Street 1, 1234 Town.', $policy->markdown);
        self::assertStringContainsString('<a href="mailto:privacy@example.org">privacy@example.org</a>', $policy->html);
        self::assertStringContainsString('<a href="https://id.example.org/privacy">privacy policy</a>', $policy->html);
        self::assertStringContainsString('Data protection officer: dpo@example.org', $policy->markdown);
        self::assertStringContainsString('Art. 6(1)(f) GDPR', $policy->markdown);
        self::assertStringContainsString('hosted by self-hosted in Netherlands', $policy->markdown);
        self::assertStringContainsString('- **Backup Co**: Off-site backups (Germany)', $policy->markdown);
        self::assertStringContainsString('kept for 14 days.', $policy->markdown);
        self::assertStringContainsString('permanently erases soft-deleted download jobs after 90 days', $policy->markdown);
        self::assertStringContainsString('deleted by the operator after 180 days', $policy->markdown);
        self::assertStringContainsString('such as Autoriteit Persoonsgegevens', $policy->markdown);

        // From the data inventory
        self::assertStringContainsString('### Your downloads', $policy->markdown);
        self::assertStringContainsString('- **A download you requested.** Purpose: Performs the download', $policy->markdown);
        self::assertStringContainsString('Security and troubleshooting. Kept for 14 days.', $policy->markdown, 'operator:logs uses the logs answer');
        self::assertStringContainsString('**The websites you submit**', $policy->markdown);
        self::assertStringNotContainsString('Python Package Index', $policy->markdown, 'no user data');
        self::assertStringNotContainsString('GitHub', $policy->markdown, 'no user data');
        self::assertStringNotContainsString('Results of the instance integrity check', $policy->markdown, 'not personal');
        self::assertStringNotContainsString('Counts of the user', $policy->markdown, 'derived views store nothing');

        self::assertStringContainsString('<h1>Privacy policy of Family downloads</h1>', $policy->html);
        self::assertStringContainsString('<h2>Your rights</h2>', $policy->html);
        self::assertStringNotContainsString('(not answered yet)', $policy->markdown);
    }

    public function testAPreviewWithIncompleteAnswersMarksWhatIsMissing(): void
    {
        $policy = $this->render(PrivacyPolicyAnswers::empty(), revision: null);

        self::assertStringContainsString('This text is not published.', $policy->markdown);
        self::assertStringContainsString('# Privacy policy of (not answered yet)', $policy->markdown);
        self::assertStringContainsString('**Logs:** (not answered yet)', $policy->markdown);
        self::assertStringNotContainsString('revision', strtok($policy->markdown, '#') ?: '');
    }

    public function testAnswersCannotInjectMarkupOrLinks(): void
    {
        $policy = $this->render(PrivacyPolicyAnswers::fromArray([
            'instanceName' => '<script>alert(1)</script>',
            'operator' => ['name' => "[Click](javascript:alert(1))\n\n# Fake heading", 'contactEmail' => 'a@example.org>)<img src=x onerror=alert(1)>'],
            'identityProvider' => ['name' => '*ID*', 'privacyPolicyUrl' => 'javascript:alert(1)'],
            'additionalText' => "We also **care**.\n\n<iframe src=\"https://evil.example\"></iframe>\n\n[x](javascript:alert(1))",
        ] + PrivacyPolicyAnswersTest::complete()), revision: 1);

        self::assertStringNotContainsString('<script', $policy->html);
        self::assertStringNotContainsString('<iframe', $policy->html);
        self::assertStringNotContainsString('<img', $policy->html);
        self::assertStringNotContainsString('href="javascript', $policy->html);
        self::assertDoesNotMatchRegularExpression('/<[^>]*\sonerror=/i', $policy->html, 'no event handler attributes');
        self::assertStringNotContainsString('<em>ID</em>', $policy->html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $policy->html);
        self::assertStringContainsString('[Click](javascript:alert(1)) # Fake heading', strip_tags(html_entity_decode($policy->html)));
        self::assertSame(1, substr_count($policy->html, '<h1>'));
        // The additional text is Markdown, minus raw HTML and unsafe links
        self::assertStringContainsString('<strong>care</strong>', $policy->html);
        self::assertStringContainsString('&lt;iframe', $policy->html);
    }

    public function testFollowsTheRuntimeConfiguration(): void
    {
        $answers = PrivacyPolicyAnswers::fromArray(PrivacyPolicyAnswersTest::complete());
        $settings = $this->settingsRepository->get();

        $default = $this->render($answers, 1);
        self::assertStringContainsString('- **Cookies for that site:** sent by default', $default->markdown);
        self::assertStringContainsString('- **Browser user agent:** sent by default', $default->markdown);
        self::assertStringContainsString('- **Referring page (referrer):** not sent by default', $default->markdown);
        self::assertStringContainsString('/.well-known/browser-extension', $default->markdown);
        self::assertStringNotContainsString('other users the operator allowed to view all downloads', $default->markdown);

        $settings->setViewAllRoleSeenAt(new \DateTimeImmutable('-10 days'));
        $viewers = $this->render($answers, 1);
        self::assertStringContainsString('Visible to you, the administrators, other users the operator allowed to view all downloads.', $viewers->markdown);
        self::assertStringContainsString(', and so can other users the operator allowed to view all downloads.', $viewers->markdown);

        $settings->setViewAllRoleSeenAt(new \DateTimeImmutable('-91 days'));
        self::assertSame($default->markdown, $this->render($answers, 1)->markdown, 'a role seen long ago no longer counts');

        $offline = $this->render($answers, 1, $this->inputsBuilder(failureReports: false, integrityOnline: false));
        self::assertStringNotContainsString('published for this version', $offline->markdown);

        $site = new Site()->setSlug('policy-test-'.bin2hex(random_bytes(4)))->setName('Policy Test Site')->setDomains(['policy-test.example']);
        $site->setDefaultSetting(new SiteSetting()->setSendCookies(false)->setSendReferrer(true)->setSendUserAgent(true));
        $this->entityManager->persist($site);
        $this->entityManager->flush();
        self::assertStringContainsString('- **Policy Test Site**: cookies not sent, referrer sent', $this->render($answers, 1)->markdown);

        $site->setEnabled(false);
        $this->entityManager->flush();
        self::assertStringNotContainsString('Policy Test Site', $this->render($answers, 1)->markdown);
    }

    private function render(PrivacyPolicyAnswers $answers, ?int $revision, ?PolicyInputsBuilder $builder = null): RenderedPolicy
    {
        $inputs = ($builder ?? $this->inputsBuilder())->build($this->settingsRepository->get(), $answers);

        return $this->renderer()->render($inputs, $revision);
    }
}
