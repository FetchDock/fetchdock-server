<?php

namespace App\Dto;

use ArrayAccess;
use Iterator;

final class CookieCollectionDTO implements Iterator, ArrayAccess
{
    private int $position = 0;

    /**
     * @var CookieDTO[]
     */
    public array $cookies = [];

    public function current(): CookieDTO
    {
        return $this->cookies[$this->position];
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): int
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->cookies[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->cookies[$offset]);
    }

    public function offsetGet(mixed $offset): CookieDTO
    {
        return $this->cookies[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->cookies[] = $value;
        } else {
            $this->cookies[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->cookies[$offset]);
    }
}

