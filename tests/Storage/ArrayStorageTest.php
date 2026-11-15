<?php

declare(strict_types=1);

namespace G\SimpleCache\Tests\Storage;

use G\SimpleCache\Storage\ArrayStorage;
use PHPUnit\Framework\TestCase;

class ArrayStorageTest extends TestCase
{
    private ArrayStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new ArrayStorage();
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

    public function testWriteOverwritesExistingKey(): void
    {
        $this->storage->write('key', 'first', null);
        $this->storage->write('key', 'second', null);
        $entry = $this->storage->read('key');

        $this->assertSame('second', $entry['value']);
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

    public function testWritePreservesType(): void
    {
        $this->storage->write('int', 42, null);
        $this->storage->write('bool', true, null);
        $this->storage->write('null', null, null);
        $this->storage->write('array', ['a' => 1], null);

        $this->assertSame(42, $this->storage->read('int')['value']);
        $this->assertTrue($this->storage->read('bool')['value']);
        $this->assertNull($this->storage->read('null')['value']);
        $this->assertSame(['a' => 1], $this->storage->read('array')['value']);
    }
}
