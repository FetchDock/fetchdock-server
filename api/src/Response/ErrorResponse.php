<?php

namespace App\Response;

class ErrorResponse implements \JsonSerializable
{
    public function __construct(
        public readonly string $message,
        public readonly bool $success = false,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return [
            'message' => $this->message,
            'success' => $this->success,
        ];
    }
}
