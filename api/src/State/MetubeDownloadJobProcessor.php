<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\DownloadJobDTO;
use App\Dto\JobAcceptedDTO;
use App\Model\MetubeDownloadJob;

class MetubeDownloadJobProcessor implements ProcessorInterface
{
    public function __construct(
        private DownloadJobQueuedProcessor $downloadJobQueuedProcessor,
    )
    {
    }

    /**
     * @param MetubeDownloadJob $data
     * @param Operation $operation
     * @param array $uriVariables
     * @param array $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JobAcceptedDTO
    {
        // Convert MetubeDownloadJob to DownloadJobDTO
        $downloadJobDTO = new DownloadJobDTO();
        $downloadJobDTO->uri = $data->url;

        // Pass to DownloadJobQueuedProcessor
        return $this->downloadJobQueuedProcessor->process($downloadJobDTO, $operation, $uriVariables, $context);
    }
}
