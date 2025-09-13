<?php

namespace App\Handler;

use App\Entity\DownloadJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DownloadJobHandler
{
    public function __invoke(
        DownloadJob $downloadJob
    )
    {
        dump($downloadJob);
    }
}
