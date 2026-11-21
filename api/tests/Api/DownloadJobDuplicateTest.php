<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use App\Repository\DownloadJobRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #94: the same media behind different URL spellings is detected as a duplicate.
 * bin/resolve_url.py is replaced by App\Tests\Double\FakeExtractorMatcher.
 */
class DownloadJobDuplicateTest extends ApiTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testOtherSpellingOfAnExistingJobIsAConflict(): void
    {
        $client = static::createClient();
        $videoId = (string) random_int(100000000, 999999999);

        $client->request('POST', '/download_jobs', $this->payload('https://vimeo.com/'.$videoId));
        $this->assertResponseStatusCodeSame(202);

        $client->request('POST', '/download_jobs', $this->payload('https://fr.vimeo.com/'.$videoId.'?share=copy#t=30'));
        $this->assertResponseStatusCodeSame(409);

        $client->request('POST', '/download_jobs', $this->payload('https://fr.vimeo.com/'.$videoId.'?share=copy#t=30', force: true));
        $this->assertResponseStatusCodeSame(202);
    }

    public function testTrackingParametersOfUnknownMediaAreIgnored(): void
    {
        $client = static::createClient();
        $uri = 'https://example.com/canonical-'.bin2hex(random_bytes(6)).'.zip';

        $client->request('POST', '/download_jobs', $this->payload($uri.'?b=2&a=1'));
        $this->assertResponseStatusCodeSame(202);

        $client->request('POST', '/download_jobs', $this->payload('HTTPS://Example.com'.parse_url($uri, PHP_URL_PATH).'?a=1&utm_source=feed&b=2'));
        $this->assertResponseStatusCodeSame(409);
    }

    public function testFilesOfAnotherSpellingAreReused(): void
    {
        $client = static::createClient();
        $videoId = (string) random_int(100000000, 999999999);
        $path = tempnam(sys_get_temp_dir(), 'canonical-test-');
        $this->temporaryFiles[] = $path;

        // Downloaded before by another user, under another spelling
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $previousJob = new DownloadJob()
            ->setUri('https://vimeo.com/'.$videoId)
            ->setCanonicalKey('ytdlp:Vimeo:'.$videoId)
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::COMPLETED)
            ->setOwner($entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => 'user']));
        $file = new DownloadedFile()->setPath($path)->setVisible(true);
        $previousJob->addFile($file);
        $entityManager->persist($previousJob);
        $entityManager->persist($file);
        $entityManager->flush();

        $response = $client->request('POST', '/download_jobs', $this->payload('https://fr.vimeo.com/'.$videoId.'?share=copy'));
        $this->assertResponseStatusCodeSame(202);

        $job = self::getContainer()->get(DownloadJobRepository::class)->findOneBy(['uuid' => $response->toArray()['jobUuid']]);
        $this->assertSame(DownloadStateEnum::ALREADY_EXISTS, $job->getState());
        $this->assertSame('https://fr.vimeo.com/'.$videoId.'?share=copy', $job->getUri());
        $this->assertSame('ytdlp:Vimeo:'.$videoId, $job->getCanonicalKey());
        $this->assertSame([$path], $job->getFiles()->map(static fn (DownloadedFile $file): ?string => $file->getPath())->getValues());
    }

    private function payload(string $uri, bool $force = false): array
    {
        return [
            'json' => ['uri' => $uri, 'downloader' => 'mock', 'force' => $force],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Basic '.base64_encode('admin:adminpass'),
            ],
        ];
    }
}
