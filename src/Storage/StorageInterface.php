<?php

declare(strict_types=1);

namespace G\SimpleCache\Storage;

/**
 * Storage driver used by the cache.
 *
 * A driver reports a failed write, removal or flush by throwing any
 * Throwable: the cache turns it into the `false` result required by PSR-16.
 * Removing a missing entry is not a failure.
 */
interface StorageInterface
{
    /**
     * Reads a cache entry.
     *
     * @return array{value: mixed, expiresAt: int|null}|null The stored entry, or null if not found.
     */
    public function read(string $key): ?array;

    /**
     * Writes a cache entry.
     *
     * @param int|null $expiresAt Absolute Unix timestamp of expiration, or null for no expiry.
     */
    public function write(string $key, mixed $value, ?int $expiresAt): void;

    /**
     * Removes a cache entry.
     */
    public function remove(string $key): void;

    /**
     * Removes all cache entries.
     */
    public function flush(): void;
}
