<?php

namespace App\Service\Privacy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The version of the generated privacy policy text (ADR 0013).
 *
 * The text follows from its inputs: the data inventory and the policy templates. privacy/policy-version.yaml numbers
 * every state of those inputs by their digest, and PolicyVersionTest fails when the inputs changed without a new
 * version, so every published text can name the version it was generated from.
 */
final class PolicyTextVersion
{
    public const string VERSION_FILE = 'privacy/policy-version.yaml';
    public const string INVENTORY_FILE = 'privacy/data-inventory.yaml';
    public const string TEMPLATE_DIR = 'templates/privacy';

    /** @var list<array{version: string, digest: string, changes: string}>|null */
    private ?array $versions = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * The current text version, the last entry of privacy/policy-version.yaml.
     */
    public function version(): string
    {
        $versions = $this->versions();

        return $versions[array_key_last($versions)]['version'];
    }

    /**
     * Every recorded version, oldest first.
     *
     * @return list<array{version: string, digest: string, changes: string}>
     */
    public function versions(): array
    {
        if (null === $this->versions) {
            $data = Yaml::parseFile($this->projectDir.'/'.self::VERSION_FILE);
            $versions = $data['versions'] ?? null;
            if (!\is_array($versions) || [] === $versions || !array_is_list($versions)) {
                throw new \UnexpectedValueException(self::VERSION_FILE.' needs a non-empty "versions" list');
            }
            $this->versions = array_map(static fn (array $entry): array => [
                'version' => (string) ($entry['version'] ?? ''),
                'digest' => (string) ($entry['digest'] ?? ''),
                'changes' => (string) ($entry['changes'] ?? ''),
            ], $versions);
        }

        return $this->versions;
    }

    /**
     * The files the policy text is generated from, relative to the project directory, sorted.
     *
     * @return list<string>
     */
    public function inputFiles(): array
    {
        $files = [self::INVENTORY_FILE];
        $templateDir = $this->projectDir.'/'.self::TEMPLATE_DIR;
        if (is_dir($templateDir)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = self::TEMPLATE_DIR.'/'.str_replace('\\', '/', substr($file->getPathname(), \strlen($templateDir) + 1));
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * sha256 over the input files' paths and contents, independent of line endings.
     */
    public function inputsDigest(): string
    {
        $context = hash_init('sha256');
        foreach ($this->inputFiles() as $path) {
            $content = file_get_contents($this->projectDir.'/'.$path);
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Cannot read %s', $path));
            }
            hash_update($context, $path."\0".hash('sha256', str_replace("\r\n", "\n", $content))."\n");
        }

        return hash_final($context);
    }
}
