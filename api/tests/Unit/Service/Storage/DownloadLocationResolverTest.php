<?php

namespace App\Tests\Unit\Service\Storage;

use App\Entity\DownloadDirectory;
use App\Repository\DownloadDirectoryRepository;
use App\Service\Storage\DownloadLocationResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DownloadLocationResolverTest extends TestCase
{
    private string $root;
    /** @var array<int, DownloadDirectory> */
    private array $directories = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/resolver-test-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/downloads/site', 0700, true);
        mkdir($this->root.'/downloads-other', 0700, true);
        mkdir($this->root.'/mnt/real', 0700, true);
        symlink($this->root.'/mnt/real', $this->root.'/linked');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testPicksTheLongestMatchingDirectory(): void
    {
        $resolver = $this->resolver(['default' => '/downloads', 'site' => '/downloads/site']);

        $location = $resolver->fromAbsolute($this->root.'/downloads/site/youtube/video.mp4');
        $this->assertSame('site', $location?->directory->getName());
        $this->assertSame('youtube/video.mp4', $location->relativePath);

        $location = $resolver->fromAbsolute($this->root.'/downloads/other/video.mp4');
        $this->assertSame('default', $location?->directory->getName());
        $this->assertSame('other/video.mp4', $location->relativePath);
    }

    public function testMatchesWholePathSegmentsOnly(): void
    {
        $resolver = $this->resolver(['default' => '/downloads']);

        $this->assertNull($resolver->fromAbsolute($this->root.'/downloads-other/video.mp4'));
        $this->assertNull($resolver->fromAbsolute($this->root.'/downloads'), 'The directory itself is not a file location');
    }

    public function testNormalizesDotSegments(): void
    {
        $resolver = $this->resolver(['default' => '/downloads', 'site' => '/downloads/site']);

        $location = $resolver->fromAbsolute($this->root.'/downloads/./site/../video.mp4');
        $this->assertSame('default', $location?->directory->getName());
        $this->assertSame('video.mp4', $location->relativePath);
        $this->assertNull($resolver->fromAbsolute($this->root.'/downloads/../outside.mp4'));
    }

    public function testResolvesSymlinksOnEitherSide(): void
    {
        // The directory is configured through a link, the tool prints the real path
        $resolver = $this->resolver(['linked' => '/linked']);
        touch($this->root.'/mnt/real/video.mp4');

        $location = $resolver->fromAbsolute($this->root.'/mnt/real/video.mp4');
        $this->assertSame('linked', $location?->directory->getName());
        $this->assertSame('video.mp4', $location->relativePath);

        // The directory is configured with its real path, the tool prints the link
        $resolver = $this->resolver(['real' => '/mnt/real']);
        $this->assertSame('video.mp4', $resolver->fromAbsolute($this->root.'/linked/video.mp4')?->relativePath);
    }

    public function testResetReloadsTheDirectories(): void
    {
        $repository = $this->createMock(DownloadDirectoryRepository::class);
        $repository->expects($this->exactly(2))->method('findAll')->willReturn([]);
        $resolver = new DownloadLocationResolver($repository, $this->createStub(EntityManagerInterface::class), new NullLogger());

        $resolver->fromAbsolute('/a');
        $resolver->fromAbsolute('/b');
        $resolver->reset();
        $resolver->fromAbsolute('/c');
    }

    /**
     * @param array<string, string> $directories paths by name, relative to the test root
     */
    private function resolver(array $directories): DownloadLocationResolver
    {
        $this->directories = [];
        foreach ($directories as $name => $path) {
            $directory = new DownloadDirectory($name, $this->root.$path);
            $id = \count($this->directories) + 1;
            new \ReflectionProperty(DownloadDirectory::class, 'id')->setValue($directory, $id);
            $this->directories[$id] = $directory;
        }

        $repository = $this->createStub(DownloadDirectoryRepository::class);
        $repository->method('findAll')->willReturn(array_values($this->directories));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getReference')->willReturnCallback(fn (string $class, int $id) => $this->directories[$id]);

        return new DownloadLocationResolver($repository, $entityManager, new NullLogger());
    }
}
