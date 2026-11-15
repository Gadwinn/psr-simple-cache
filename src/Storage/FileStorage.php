<?php

declare(strict_types=1);

namespace G\SimpleCache\Storage;

use G\SimpleCache\CacheException;
use RuntimeException;

/**
 * Stores every entry in its own file inside a directory.
 */
class FileStorage implements StorageInterface
{
    private const EXPECTED_DATA_COUNT = 2;

    /**
     * @throws RuntimeException If the directory does not exist and cannot be created.
     */
    public function __construct(private readonly string $directory)
    {
        if (
            !is_dir($this->directory) &&
            !@mkdir($this->directory, 0o755, true) &&
            !is_dir($this->directory)
        ) {
            throw new RuntimeException(sprintf('Cache directory "%s" could not be created.', $this->directory));
        }
    }

    public function read(string $key): ?array
    {
        $path = $this->filePath($key);

        if (!is_file($path)) {
            return null;
        }

        // The file may vanish between the check and the read: stay silent.
        $content = @file_get_contents($path);

        if ($content === false) {
            return null;
        }

        // A corrupted file is a cache miss, not an error.
        $data = @unserialize($content, ['allowed_classes' => true]);

        if (
            !is_array($data) ||
            count($data) !== self::EXPECTED_DATA_COUNT ||
            !array_key_exists('value', $data) ||
            !array_key_exists('expiresAt', $data)
        ) {
            return null;
        }

        return [
            'value' => $data['value'],
            'expiresAt' => is_int($data['expiresAt']) ? $data['expiresAt'] : null
        ];
    }

    /**
     * The entry is written to a temporary file then renamed, so that a
     * concurrent reader never sees a partial file.
     *
     * @throws CacheException If the entry cannot be written.
     */
    public function write(string $key, mixed $value, ?int $expiresAt): void
    {
        $path = $this->filePath($key);
        $temporaryPath = $path . uniqid('.', true) . '.tmp';

        if (@file_put_contents($temporaryPath, serialize(compact('value', 'expiresAt')), LOCK_EX) === false) {
            throw new CacheException(sprintf('Cache file "%s" could not be written.', $temporaryPath));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new CacheException(sprintf('Cache file "%s" could not be written.', $path));
        }
    }

    /**
     * @throws CacheException If the entry exists and cannot be removed.
     */
    public function remove(string $key): void
    {
        $path = $this->filePath($key);

        if (!$this->removeFile($path)) {
            throw new CacheException(sprintf('Cache file "%s" could not be removed.', $path));
        }
    }

    /**
     * @throws CacheException If at least one entry cannot be removed.
     */
    public function flush(): void
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.cache');

        if ($files === false) {
            throw new CacheException(sprintf('Cache directory "%s" could not be read.', $this->directory));
        }

        $failed = false;

        foreach ($files as $file) {
            $failed = !$this->removeFile($file) || $failed;
        }

        if ($failed) {
            throw new CacheException(sprintf('Cache directory "%s" could not be emptied.', $this->directory));
        }
    }

    /**
     * Deletes a file; a file that is already gone counts as a success.
     */
    private function removeFile(string $path): bool
    {
        return !is_file($path) || @unlink($path) || !is_file($path);
    }

    private function filePath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }
}
