<?php

namespace App\Handler;

use App\Dto\DownloadJobDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DownloadJobDTOHandler
{
    public function __invoke(
        DownloadJobDTO $downloadJob
    )
    {
        dd($downloadJob);
    }
}
