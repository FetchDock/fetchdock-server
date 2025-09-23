<?php

namespace App\Dto;

use App\Enum\JobTypeEnum;

class JobAcceptedDTO
{
    public string $jobUuid;
    public string $token;
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

    public function getJobUuid(): string
    {
        return $this->jobUuid;
    }

    public function setJobUuid(string $jobUuid): JobAcceptedDTO
    {
        $this->jobUuid = $jobUuid;
        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): JobAcceptedDTO
    {
        $this->token = $token;
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
