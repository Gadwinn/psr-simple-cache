<?php

declare(strict_types=1);

namespace G\SimpleCache\Storage;

class ArrayStorage implements StorageInterface
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $data = [];

    public function read(string $key): ?array
    {
        return $this->data[$key] ?? null;
    }

    public function write(string $key, mixed $value, ?int $expiresAt): void
    {
        $this->data[$key] = compact('value', 'expiresAt');
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function flush(): void
    {
        $this->data = [];
    }
}
