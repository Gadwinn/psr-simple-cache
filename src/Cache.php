<?php

declare(strict_types=1);

namespace G\SimpleCache;

use G\SimpleCache\Storage\ArrayStorage;
use G\SimpleCache\Storage\StorageInterface;
use Psr\SimpleCache\CacheInterface;
use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * PSR-16 cache delegating persistence to a storage driver.
 */
class Cache implements CacheInterface
{
    /**
     * Characters reserved by PSR-16 for future extensions.
     */
    private const RESERVED_CHARACTERS = '{}()/\@:';

    /**
     * @param StorageInterface $storage Storage driver, in-memory by default.
     * @param int|null $defaultTtl TTL in seconds applied when none is given, null to cache forever.
     */
    public function __construct(
        private readonly StorageInterface $storage = new ArrayStorage(),
        private readonly ?int $defaultTtl = null
    ) { //
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $entry = $this->storage->read($key);

        if ($entry === null) {
            return $default;
        }

        if ($this->isExpired($entry)) {
            $this->remove($key);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expiresAt = $this->expiresAt($ttl ?? $this->defaultTtl);

        // PSR-16 §1.2: a negative or zero TTL MUST delete the item.
        if ($expiresAt !== null && $expiresAt <= time()) {
            return $this->remove($key);
        }

        try {
            $this->storage->write($key, $value, $expiresAt);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return $this->remove($key);
    }

    public function clear(): bool
    {
        try {
            $this->storage->flush();
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($this->normalizeKeys($keys) as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * Every key is validated before anything is written.
     *
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $entries = [];

        foreach ($values as $key => $value) {
            $entries[] = [$this->normalizeKey($key), $value];
        }

        $success = true;

        foreach ($entries as [$key, $value]) {
            $success = $this->set($key, $value, $ttl) && $success;
        }

        return $success;
    }

    /**
     * Every key is validated before anything is deleted.
     *
     * @param iterable<mixed> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;

        foreach ($this->normalizeKeys($keys) as $key) {
            $success = $this->remove($key) && $success;
        }

        return $success;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        $entry = $this->storage->read($key);

        if ($entry === null) {
            return false;
        }

        if ($this->isExpired($entry)) {
            $this->remove($key);
            return false;
        }

        return true;
    }

    /**
     * Removes an entry, turning any storage failure into a false result.
     */
    private function remove(string $key): bool
    {
        try {
            $this->storage->remove($key);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Converts a TTL into an absolute Unix timestamp, null meaning no expiry.
     */
    private function expiresAt(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable())->add($ttl)->getTimestamp();
        }

        return time() + $ttl;
    }

    /**
     * @param array{value: mixed, expiresAt: int|null} $entry
     */
    private function isExpired(array $entry): bool
    {
        return $entry['expiresAt'] !== null && $entry['expiresAt'] <= time();
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function normalizeKeys(iterable $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[] = $this->normalizeKey($key);
        }

        return $normalized;
    }

    /**
     * Returns a validated string key. Integers are accepted because PHP turns
     * numeric string array keys into integers.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeKey(mixed $key): string
    {
        if (is_int($key)) {
            $key = (string) $key;
        }

        if (!is_string($key)) {
            throw new InvalidArgumentException(sprintf('Invalid key. Key must be a string, %s given.', gettype($key)));
        }

        $this->validateKey($key);

        return $key;
    }

    /**
     * @param string $key
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            throw new InvalidArgumentException(
                'Invalid key. Key must be a non-empty string without any of the reserved characters {}()/\@:'
            );
        }
    }
}
