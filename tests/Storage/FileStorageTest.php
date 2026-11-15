<?php

declare(strict_types=1);

namespace G\SimpleCache\Tests\Storage;

use G\SimpleCache\Storage\FileStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileStorageTest extends TestCase
{
    private string $dir;
    private FileStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'psr_cache_test_' . uniqid();
        $this->storage = new FileStorage($this->dir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.cache');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testCreatesDirectoryOnInstantiation(): void
    {
        $this->assertDirectoryExists($this->dir);
    }

    public function testThrowsWhenDirectoryCannotBeCreated(): void
    {
        // Place a regular file where the directory is expected — mkdir will fail on POSIX systems.
        $blockerFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'psr_cache_blocker_' . uniqid();
        file_put_contents($blockerFile, '');

        $this->expectException(RuntimeException::class);

        try {
            new FileStorage($blockerFile . DIRECTORY_SEPARATOR . 'subdir');
        } finally {
            @unlink($blockerFile);
        }
    }

    public function testReadReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->storage->read('missing'));
    }

    public function testWriteAndRead(): void
    {
        $this->storage->write('key', 'value', null);
        $entry = $this->storage->read('key');

        $this->assertIsArray($entry);
        $this->assertSame('value', $entry['value']);
        $this->assertNull($entry['expiresAt']);
    }

    public function testWriteWithExpiresAt(): void
    {
        $expiresAt = time() + 60;
        $this->storage->write('key', 'value', $expiresAt);
        $entry = $this->storage->read('key');

        $this->assertIsArray($entry);
        $this->assertSame($expiresAt, $entry['expiresAt']);
    }

    public function testWriteAndReadComplexValue(): void
    {
        $value = ['nested' => ['array' => true], 'count' => 42];
        $this->storage->write('key', $value, null);
        $entry = $this->storage->read('key');

        $this->assertSame($value, $entry['value']);
    }

    public function testWritePreservesType(): void
    {
        $this->storage->write('int', 42, null);
        $this->storage->write('bool', true, null);
        $this->storage->write('null_val', null, null);

        $this->assertSame(42, $this->storage->read('int')['value']);
        $this->assertTrue($this->storage->read('bool')['value']);
        $this->assertNull($this->storage->read('null_val')['value']);
    }

    public function testRemove(): void
    {
        $this->storage->write('key', 'value', null);
        $this->storage->remove('key');

        $this->assertNull($this->storage->read('key'));
    }

    public function testRemoveNonExistentKeyDoesNotThrow(): void
    {
        $this->storage->remove('missing');

        $this->assertNull($this->storage->read('missing'));
    }

    public function testFlush(): void
    {
        $this->storage->write('key1', 'value1', null);
        $this->storage->write('key2', 'value2', null);
        $this->storage->flush();

        $this->assertNull($this->storage->read('key1'));
        $this->assertNull($this->storage->read('key2'));
    }

    public function testPersistsAcrossInstances(): void
    {
        $this->storage->write('key', 'value', null);

        $anotherInstance = new FileStorage($this->dir);
        $entry = $anotherInstance->read('key');

        $this->assertIsArray($entry);
        $this->assertSame('value', $entry['value']);
    }

    public function testBasicCacheOperationsViaCache(): void
    {
        $cache = new \G\SimpleCache\Cache($this->storage);

        $cache->set('foo', 'bar');
        $this->assertEquals('bar', $cache->get('foo'));
        $this->assertTrue($cache->has('foo'));

        $cache->delete('foo');
        $this->assertFalse($cache->has('foo'));

        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->clear();
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function testExpiredEntryViaCache(): void
    {
        $cache = new \G\SimpleCache\Cache($this->storage);

        $cache->set('key', 'value', 60);
        $this->assertTrue($cache->has('key'));

        $this->storage->write('key', 'value', time() - 1);

        $this->assertFalse($cache->has('key'));
        $this->assertNull($this->storage->read('key'));
    }

    public function testOverwriteLeavesASingleFile(): void
    {
        $this->storage->write('key', 'first', null);
        $this->storage->write('key', 'second', null);

        $this->assertSame('second', $this->storage->read('key')['value'] ?? null);
        $this->assertCount(1, array_diff((array) scandir($this->dir), ['.', '..']));
    }

    public function testCorruptedFileIsAMissWithoutError(): void
    {
        $this->storage->write('key', 'value', null);
        $files = (array) glob($this->dir . DIRECTORY_SEPARATOR . '*.cache');
        $this->assertCount(1, $files);
        file_put_contents((string) $files[0], 'a:2:{s:5:"value";s:3:"tr');

        $errors = [];
        set_error_handler(static function (int $no, string $str) use (&$errors): bool {
            if ((error_reporting() & $no) === 0) {
                return true; // silenced with the @ operator
            }
            $errors[] = $str;

            return true;
        });

        try {
            $entry = $this->storage->read('key');
        } finally {
            restore_error_handler();
        }

        $this->assertNull($entry);
        $this->assertSame([], $errors);
    }

    public function testFailedWriteIsReportedAsFalseByCache(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'psr_cache_gone_' . uniqid();
        $cache = new \G\SimpleCache\Cache(new FileStorage($dir));
        rmdir($dir);

        $this->assertFalse($cache->set('key', 'value'));
        $this->assertTrue($cache->delete('key'));
    }

    public function testUnserializableValueIsReportedAsFalseByCache(): void
    {
        $cache = new \G\SimpleCache\Cache($this->storage);

        $this->assertFalse($cache->set('key', static fn (): int => 1));
        $this->assertFalse($cache->has('key'));
    }
}
