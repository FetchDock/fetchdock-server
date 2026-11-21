<?php

namespace App\Tests\Integration\Mercure;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Mercure\AdminTopics;
use App\Mercure\UserTopics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * End-to-end checks against the real Mercure hub (#88): the hub, not only our code, must enforce who receives what.
 *
 * Subscribers connect to the hub over HTTP with the tokens that POST /users/me/subscription-token issues, and updates
 * are published through the app's configured hub, as the app itself does.
 */
class HubTest extends ApiTestCase
{
    private const float RECEIVE_TIMEOUT = 2.0;

    private HttpClientInterface $hubClient;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->hubClient = HttpClient::create(['timeout' => 5]);
    }

    public function testAnonymousSubscribersAreRefused(): void
    {
        foreach (['*', UserTopics::subscribeSelector($this->user('user'))] as $topic) {
            $response = $this->hubClient->request('GET', $this->hubUrl(), ['query' => ['topic' => $topic]]);

            $this->assertSame(401, $response->getStatusCode(), "Anonymous subscription to '{$topic}' must be refused");
        }
    }

    public function testExpiredTokensAreRejected(): void
    {
        $token = $this->subscriberFactory()->create(['*'], null, ['exp' => new \DateTimeImmutable('-1 minute')]);

        $this->assertSame(401, $this->subscribe($token)->getStatusCode());
    }

    public function testTokensSignedWithAnotherKeyAreRejected(): void
    {
        $token = new LcobucciFactory(str_repeat('not-the-hub-key-', 4))->create(['*']);

        $this->assertSame(401, $this->subscribe($token)->getStatusCode());
    }

    public function testTamperedTokensAreRejected(): void
    {
        // A real token whose claims are widened to every topic, keeping the original signature
        [$header, , $signature] = explode('.', $this->subscriptionToken('user', 'userpass'));
        $payload = rtrim(strtr(base64_encode(json_encode(['mercure' => ['subscribe' => ['*']], 'exp' => time() + 3600])), '+/', '-_'), '=');

        $this->assertSame(401, $this->subscribe("{$header}.{$payload}.{$signature}")->getStatusCode());
    }

    public function testSubscriberTokensCannotPublish(): void
    {
        $response = $this->hubClient->request('POST', $this->hubUrl(), [
            'headers' => ['Authorization' => 'Bearer '.$this->subscriptionToken('admin', 'adminpass')],
            'body' => ['topic' => UserTopics::prefix($this->user('user')).'/download_jobs/forged', 'data' => '{}', 'private' => 'on'],
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testUsersOnlyReceiveTheirOwnJobUpdatesAndOutput(): void
    {
        $user = $this->user('user');
        $otherUser = $this->user('admin');
        $subscription = $this->subscribe($this->subscriptionToken('user', 'userpass'));
        $this->assertSame(200, $subscription->getStatusCode());

        $otherJob = $this->jobOwnedBy($otherUser);
        $this->publish(UserTopics::downloadJob($otherJob), 'other-user-job');
        $this->publish(UserTopics::downloadJobOutput($otherJob), 'other-user-output');
        $ownJob = $this->jobOwnedBy($user);
        $this->publish(UserTopics::downloadJob($ownJob), 'own-job');
        $this->publish(UserTopics::downloadJobOutput($ownJob), 'own-output');

        // Updates arrive in order, so the other user's would have arrived before the user's own
        $this->assertSame(['own-job', 'own-output'], $this->receive($subscription, 2));
    }

    public function testOnlyAdminsReceiveAdminTopics(): void
    {
        $adminTopic = self::getContainer()->get(AdminTopics::class)->downloadJob($this->jobOwnedBy($this->user('user'))->getUuid()->toRfc4122());
        $admin = $this->subscribe($this->subscriptionToken('admin', 'adminpass'));
        $user = $this->subscribe($this->subscriptionToken('user', 'userpass'));
        $this->assertSame(200, $admin->getStatusCode());
        $this->assertSame(200, $user->getStatusCode());

        $this->publish($adminTopic, 'admin-job');
        $this->publish(UserTopics::prefix($this->user('user')).'/marker', 'user-marker');

        $this->assertSame(['admin-job'], $this->receive($admin, 1));
        $this->assertSame(['user-marker'], $this->receive($user, 1));
    }

    /**
     * Subscribes to every topic: the hub only delivers what the token's "mercure.subscribe" claim allows.
     */
    private function subscribe(string $token): ResponseInterface
    {
        $response = $this->hubClient->request('GET', $this->hubUrl(), [
            'query' => ['topic' => '*'],
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'text/event-stream'],
        ]);
        // Waits for the response headers: once the hub sent them, the subscription is registered
        $response->getStatusCode();

        return $response;
    }

    private function publish(?string $topic, string $data): void
    {
        $this->assertNotNull($topic);
        self::getContainer()->get(HubInterface::class)->publish(new Update($topic, $data, private: true));
    }

    /**
     * Reads the data of $count events from the subscription, then checks that no more events arrive.
     *
     * @return list<string>
     */
    private function receive(ResponseInterface $subscription, int $count): array
    {
        $buffer = '';
        $data = [];
        $deadline = microtime(true) + self::RECEIVE_TIMEOUT;
        try {
            foreach ($this->hubClient->stream($subscription, 0.2) as $chunk) {
                if (!$chunk->isTimeout()) {
                    $buffer .= $chunk->getContent();
                    while (false !== ($end = strpos($buffer, "\n\n"))) {
                        $event = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 2);
                        if (preg_match_all('/^data: ?(.*)$/m', $event, $matches)) {
                            $data[] = implode("\n", $matches[1]);
                        }
                    }
                }
                // Keeps listening until the deadline even after $count events, to catch unexpected extra ones
                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        } finally {
            $subscription->cancel();
        }

        $this->assertCount($count, $data, 'Unexpected number of events received: '.json_encode($data));

        return $data;
    }

    private function subscriptionToken(string $username, string $password): string
    {
        $response = static::createClient()->request('POST', '/users/me/subscription-token', [
            'headers' => ['Authorization' => 'Basic '.base64_encode($username.':'.$password)],
        ]);
        $this->assertResponseIsSuccessful();

        return $response->toArray()['token'];
    }

    private function subscriberFactory(): LcobucciFactory
    {
        return new LcobucciFactory(self::getContainer()->getParameter('app.mercure.subscriber_jwt_secret'));
    }

    private function hubUrl(): string
    {
        return self::getContainer()->get(HubInterface::class)->getUrl();
    }

    private function jobOwnedBy(OidcSubjectIdentifier $owner): DownloadJob
    {
        return new DownloadJob()->setOwner($owner);
    }

    private function user(string $subject): OidcSubjectIdentifier
    {
        $user = static::getContainer()->get(EntityManagerInterface::class)->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $subject]);
        $this->assertNotNull($user, "Missing test fixture for subject '{$subject}', run `make prep-test`");

        return $user;
    }
}
