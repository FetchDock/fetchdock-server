<?php

namespace App\Dto;

use App\Enum\JobTypeEnum;

class JobAcceptedDTO
{
    public int $jobId;
    public JobTypeEnum $jobType;
    public string $status = 'Accepted';
    public string $message = 'Your job has been accepted and is being processed.';

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): JobAcceptedDTO
    {
        $this->status = $status;
        return $this;
    }

    public function getJobId(): int
    {
        return $this->jobId;
    }

    public function setJobId(int $jobId): JobAcceptedDTO
    {
        $this->jobId = $jobId;
        return $this;
    }

    public function getJobType(): JobTypeEnum
    {
        return $this->jobType;
    }

    public function setJobType(JobTypeEnum $jobType): JobAcceptedDTO
    {
        $this->jobType = $jobType;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): JobAcceptedDTO
    {
        $this->message = $message;
        return $this;
    }


}
