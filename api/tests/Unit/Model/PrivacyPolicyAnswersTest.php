<?php

namespace App\Tests\Unit\Model;

use App\Model\PrivacyPolicyAnswers;
use PHPUnit\Framework\TestCase;

final class PrivacyPolicyAnswersTest extends TestCase
{
    public function testEmptyAnswersMissEveryRequiredOne(): void
    {
        $answers = PrivacyPolicyAnswers::empty();

        self::assertSame(PrivacyPolicyAnswers::REQUIRED, $answers->missing());
        self::assertFalse($answers->isComplete());
    }

    public function testNormalizesToAFixedShape(): void
    {
        $answers = PrivacyPolicyAnswers::fromArray([
            'instanceName' => "  Family\n downloads ",
            'operator' => ['name' => 'Alex', 'address' => " Street 1\r\nTown ", 'contactEmail' => '', 'unknown' => 'x'],
            'hosting' => ['provider' => 'self-hosted', 'country' => 'nl'],
            'processors' => [['name' => 'Backup Co', 'country' => 'de'], ['purpose' => 'no name'], 'not a map'],
            'retention' => ['logsDays' => '14', 'softDeletedJobsDays' => -1, 'reportsDays' => 'soon'],
            'legalBasis' => 'consent',
            'additionalText' => str_repeat('a', PrivacyPolicyAnswers::ADDITIONAL_TEXT_MAX_LENGTH + 5),
            'extra' => true,
        ])->toArray();

        self::assertSame('Family downloads', $answers['instanceName']);
        self::assertSame(['name' => 'Alex', 'address' => "Street 1\nTown", 'contactEmail' => null, 'dpoContact' => null], $answers['operator']);
        self::assertSame(['name' => null, 'privacyPolicyUrl' => null], $answers['identityProvider']);
        self::assertSame(['provider' => 'self-hosted', 'country' => 'NL'], $answers['hosting']);
        self::assertSame([['name' => 'Backup Co', 'purpose' => null, 'country' => 'DE']], $answers['processors']);
        self::assertSame(['logsDays' => 14, 'backups' => null, 'softDeletedJobsDays' => null, 'reportsDays' => null], $answers['retention']);
        self::assertNull($answers['legalBasis'], 'only the listed legal bases are kept');
        self::assertSame(PrivacyPolicyAnswers::ADDITIONAL_TEXT_MAX_LENGTH, mb_strlen($answers['additionalText']));
        self::assertArrayNotHasKey('extra', $answers);
    }

    public function testCompleteAnswers(): void
    {
        $answers = PrivacyPolicyAnswers::fromArray(self::complete());

        self::assertSame([], $answers->missing());
        self::assertTrue($answers->isComplete());
        self::assertSame(['operator.name', 'operator.contactEmail'], PrivacyPolicyAnswers::fromArray(['operator' => ['contactEmail' => ' ']] + self::complete())->missing());
    }

    public function testTheHashFollowsTheNormalizedAnswers(): void
    {
        $answers = PrivacyPolicyAnswers::fromArray(self::complete());

        self::assertSame($answers->hash(), PrivacyPolicyAnswers::fromArray(self::complete() + ['ignored' => 1])->hash());
        self::assertSame($answers->hash(), PrivacyPolicyAnswers::fromArray($answers->toArray())->hash());
        self::assertNotSame($answers->hash(), PrivacyPolicyAnswers::fromArray(['instanceName' => 'Other'] + self::complete())->hash());
    }

    /**
     * @return array<string, mixed>
     */
    public static function complete(): array
    {
        return [
            'instanceName' => 'Family downloads',
            'operator' => ['name' => 'Alex Example', 'contactEmail' => 'privacy@example.org'],
            'identityProvider' => ['name' => 'Example ID'],
            'hosting' => ['provider' => 'self-hosted', 'country' => 'NL'],
            'retention' => ['logsDays' => 14],
            'legalBasis' => 'legitimate_interest',
        ];
    }
}
