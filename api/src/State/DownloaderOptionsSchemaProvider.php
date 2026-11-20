<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Factory\DownloaderFactory;
use App\Resource\DownloaderOptionsSchema;
use App\Service\Downloader\Options\OptionsSchemaFactory;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * @implements ProviderInterface<DownloaderOptionsSchema>
 */
final readonly class DownloaderOptionsSchemaProvider implements ProviderInterface
{
    public function __construct(
        private DownloaderFactory $downloaderFactory,
        private OptionsSchemaFactory $schemaFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?DownloaderOptionsSchema
    {
        $downloader = (string) $uriVariables['id'];
        if (!$this->downloaderFactory->isValidDownloader($downloader)) {
            return null;
        }

        try {
            return DownloaderOptionsSchema::fromSchema($downloader, $this->schemaFactory->getSchema($downloader));
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailableHttpException(30, 'Unable to list the downloader options right now.', $e);
        }
    }
}
