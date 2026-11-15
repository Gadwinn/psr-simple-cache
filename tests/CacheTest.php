<?php

declare(strict_types=1);

namespace G\SimpleCache\Tests;

use ArrayIterator;
use DateInterval;
use G\SimpleCache\Cache;
use G\SimpleCache\InvalidArgumentException;
use G\SimpleCache\Storage\ArrayStorage;
use G\SimpleCache\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;
use RuntimeException;
use stdClass;

final class CacheTest extends TestCase
{
    private ArrayStorage $storage;
    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new ArrayStorage();
        $this->cache = new Cache($this->storage);
    }

    /**
     * A storage whose write operations always fail.
     */
    private function failingStorage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function read(string $key): ?array
            {
                return null;
            }

            public function write(string $key, mixed $value, ?int $expiresAt): void
            {
                throw new RuntimeException('write failed');
            }

            public function remove(string $key): void
            {
                throw new RuntimeException('remove failed');
            }

            public function flush(): void
            {
                throw new RuntimeException('flush failed');
            }
        };
    }

    // -------------------------------------------------------------------------
    // get / set / has / delete / clear
    // -------------------------------------------------------------------------

    public function testDefaultStorageIsInMemory(): void
    {
        $cache = new Cache();

        $this->assertTrue($cache->set('key', 'value'));
        $this->assertSame('value', $cache->get('key'));
    }

    public function testGetAndSet(): void
    {
        $this->assertTrue($this->cache->set('key', 'value'));

        $this->assertSame('value', $this->cache->get('key'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        $this->assertNull($this->cache->get('missing'));
        $this->assertSame('default_value', $this->cache->get('missing', 'default_value'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valueProvider(): iterable
    {
        yield 'string' => ['value'];
        yield 'empty string' => [''];
        yield 'int' => [42];
        yield 'zero' => [0];
        yield 'float' => [1.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [['a' => [1, 2], 'b' => null]];
    }

    #[DataProvider('valueProvider')]
    public function testValuesAreReturnedWithTheirType(mixed $value): void
    {
        $this->cache->set('key', $value);

        $this->assertSame($value, $this->cache->get('key', 'default'));
        $this->assertTrue($this->cache->has('key'));
    }

    public function testObjectIsReturnedEqual(): void
    {
        $object = new stdClass();
        $object->name = 'x';
        $this->cache->set('key', $object);

        $this->assertEquals($object, $this->cache->get('key'));
    }

    public function testStoredNullIsAHitForHas(): void
    {
        $this->cache->set('key', null);

        $this->assertTrue($this->cache->has('key'));
    }

    public function testSetOverwrites(): void
    {
        $this->cache->set('key', 'first');
        $this->cache->set('key', 'second');

        $this->assertSame('second', $this->cache->get('key'));
    }

    public function testHas(): void
    {
        $this->cache->set('existing_key', 'value');

        $this->assertTrue($this->cache->has('existing_key'));
        $this->assertFalse($this->cache->has('nonexistent_key'));
    }

    public function testDelete(): void
    {
        $this->cache->set('key', 'value');

        $this->assertTrue($this->cache->delete('key'));
        $this->assertFalse($this->cache->has('key'));
    }

    public function testDeleteNonExistentKey(): void
    {
        // PSR-16: delete() returns false only on error; a missing key is not an error.
        $this->assertTrue($this->cache->delete('non_existent_key'));
    }

    public function testClear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $this->assertTrue($this->cache->clear());
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    // -------------------------------------------------------------------------
    // TTL
    // -------------------------------------------------------------------------

    public function testSetWithoutTtlNeverExpires(): void
    {
        $this->cache->set('key', 'value');

        $this->assertNull($this->storage->read('key')['expiresAt'] ?? null);
    }

    public function testSetWithIntegerTtl(): void
    {
        $before = time();
        $this->assertTrue($this->cache->set('key', 'value', 60));
        $after = time();

        $expiresAt = $this->storage->read('key')['expiresAt'] ?? null;
        $this->assertGreaterThanOrEqual($before + 60, $expiresAt);
        $this->assertLessThanOrEqual($after + 60, $expiresAt);
        $this->assertTrue($this->cache->has('key'));
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $before = time();
        $this->assertTrue($this->cache->set('key', 'value', new DateInterval('PT1H')));
        $after = time();

        $expiresAt = $this->storage->read('key')['expiresAt'] ?? null;
        $this->assertGreaterThanOrEqual($before + 3600, $expiresAt);
        $this->assertLessThanOrEqual($after + 3600, $expiresAt);
    }

    public function testDefaultTtlAppliesWhenNoneIsGiven(): void
    {
        $cache = new Cache($this->storage, defaultTtl: 60);
        $before = time();
        $cache->set('key', 'value');

        $this->assertGreaterThanOrEqual($before + 60, $this->storage->read('key')['expiresAt'] ?? null);
    }

    public function testExplicitTtlWinsOverDefaultTtl(): void
    {
        $cache = new Cache($this->storage, defaultTtl: 60);
        $before = time();
        $cache->set('key', 'value', 3600);

        $this->assertGreaterThanOrEqual($before + 3600, $this->storage->read('key')['expiresAt'] ?? null);
    }

    /**
     * @return iterable<string, array{int|DateInterval}>
     */
    public static function expiredTtlProvider(): iterable
    {
        $negative = new DateInterval('PT10S');
        $negative->invert = 1;

        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'empty interval' => [new DateInterval('PT0S')];
        yield 'negative interval' => [$negative];
    }

    #[DataProvider('expiredTtlProvider')]
    public function testZeroOrNegativeTtlDeletesTheItem(int|DateInterval $ttl): void
    {
        $this->cache->set('key', 'old');

        // PSR-16 §1.2: the item MUST be deleted from the cache if it exists.
        $this->assertTrue($this->cache->set('key', 'new', $ttl));
        $this->assertFalse($this->cache->has('key'));
        $this->assertNull($this->storage->read('key'));
    }

    public function testSetMultipleWithNegativeTtlDeletesTheItems(): void
    {
        $this->cache->setMultiple(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertTrue($this->cache->setMultiple(['key1' => 'value1', 'key2' => 'value2'], -1));
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    public function testExpiredItemIsAMissAndIsRemoved(): void
    {
        $this->storage->write('key', 'value', time() - 1);

        $this->assertNull($this->cache->get('key'));
        $this->assertSame('fallback', $this->cache->get('key', 'fallback'));
        $this->assertNull($this->storage->read('key'));
    }

    public function testHasRemovesExpiredItem(): void
    {
        $this->storage->write('key', 'value', time() - 1);

        $this->assertFalse($this->cache->has('key'));
        $this->assertNull($this->storage->read('key'));
    }

    public function testItemExpiresAtItsExpirationTime(): void
    {
        $this->storage->write('key', 'value', time());

        $this->assertFalse($this->cache->has('key'));
    }

    // -------------------------------------------------------------------------
    // Multiple
    // -------------------------------------------------------------------------

    public function testGetMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $result = $this->cache->getMultiple(['key1', 'key2', 'nonexistent_key'], 'default_value');

        $this->assertSame(
            ['key1' => 'value1', 'key2' => 'value2', 'nonexistent_key' => 'default_value'],
            $result
        );
    }

    public function testGetMultipleAcceptsATraversable(): void
    {
        $this->cache->set('key1', 'value1');

        $result = $this->cache->getMultiple(new ArrayIterator(['key1', 'key2']));

        $this->assertSame(['key1' => 'value1', 'key2' => null], $result);
    }

    public function testSetMultiple(): void
    {
        $this->assertTrue($this->cache->setMultiple(['key1' => 'value1', 'key2' => 'value2']));

        $this->assertSame('value1', $this->cache->get('key1'));
        $this->assertSame('value2', $this->cache->get('key2'));
    }

    public function testSetMultipleAcceptsATraversableAndATtl(): void
    {
        $before = time();
        $this->assertTrue($this->cache->setMultiple(new ArrayIterator(['key1' => 'value1']), 60));

        $this->assertGreaterThanOrEqual($before + 60, $this->storage->read('key1')['expiresAt'] ?? null);
    }

    public function testSetMultipleAcceptsNumericKeys(): void
    {
        // PHP turns the numeric string key "123" into the integer 123.
        $this->assertTrue($this->cache->setMultiple(['123' => 'value']));

        $this->assertSame('value', $this->cache->get('123'));
        $this->assertSame(['123' => 'value'], $this->cache->getMultiple(array_keys(['123' => true])));
    }

    public function testSetMultipleWritesNothingWhenAKeyIsInvalid(): void
    {
        try {
            $this->cache->setMultiple(['valid' => 1, 'in:valid' => 2]);
            $this->fail('An invalid key must be rejected.');
        } catch (PsrInvalidArgumentException) {
            $this->assertFalse($this->cache->has('valid'));
        }
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->setMultiple(['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3']);

        $this->assertTrue($this->cache->deleteMultiple(new ArrayIterator(['key1', 'key2', 'missing'])));

        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
        $this->assertTrue($this->cache->has('key3'));
    }

    public function testDeleteMultipleDeletesNothingWhenAKeyIsInvalid(): void
    {
        $this->cache->set('valid', 1);

        try {
            $this->cache->deleteMultiple(['valid', 'in:valid']);
            $this->fail('An invalid key must be rejected.');
        } catch (PsrInvalidArgumentException) {
            $this->assertTrue($this->cache->has('valid'));
        }
    }

    // -------------------------------------------------------------------------
    // Keys
    // -------------------------------------------------------------------------

    public function testMinimumKeySetIsSupported(): void
    {
        $key = 'AZaz09_.' . str_repeat('k', 56);
        $this->assertSame(64, strlen($key));

        $this->assertTrue($this->cache->set($key, 'value'));
        $this->assertSame('value', $this->cache->get($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function extendedKeyProvider(): iterable
    {
        yield 'hyphen' => ['user-42'];
        yield 'space' => ['a key'];
        yield 'unicode' => ['clé_été'];
        yield 'long' => [str_repeat('k', 300)];
        yield 'other punctuation' => ['a|b#c=d,e;f'];
    }

    #[DataProvider('extendedKeyProvider')]
    public function testKeysBeyondTheMinimumSetAreSupported(string $key): void
    {
        $this->assertTrue($this->cache->set($key, 'value'));
        $this->assertSame('value', $this->cache->get($key));
        $this->assertSame([$key => 'value'], $this->cache->getMultiple([$key]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeyProvider(): iterable
    {
        yield 'empty' => [''];
        foreach (['{', '}', '(', ')', '/', '\\', '@', ':'] as $reserved) {
            yield 'reserved ' . $reserved => ['key' . $reserved];
        }
    }

    #[DataProvider('invalidKeyProvider')]
    public function testInvalidKeyThrowsOnEveryMethod(string $key): void
    {
        $calls = [
            fn (): mixed => $this->cache->get($key),
            fn (): bool => $this->cache->set($key, 'value'),
            fn (): bool => $this->cache->has($key),
            fn (): bool => $this->cache->delete($key),
            fn (): iterable => $this->cache->getMultiple([$key]),
            fn (): bool => $this->cache->setMultiple(new ArrayIterator([$key => 'value'])),
            fn (): bool => $this->cache->deleteMultiple([$key]),
        ];

        foreach ($calls as $index => $call) {
            try {
                $call();
                $this->fail(sprintf('Call #%d accepted an invalid key.', $index));
            } catch (PsrInvalidArgumentException $exception) {
                $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            }
        }
    }

    public function testNonStringKeyThrowsInvalidArgumentException(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);

        $this->cache->getMultiple([['not', 'a', 'key']]);
    }

    // -------------------------------------------------------------------------
    // Storage failures
    // -------------------------------------------------------------------------

    public function testStorageFailuresAreReportedAsFalse(): void
    {
        $cache = new Cache($this->failingStorage());

        $this->assertFalse($cache->set('key', 'value'));
        $this->assertFalse($cache->set('key', 'value', -1));
        $this->assertFalse($cache->delete('key'));
        $this->assertFalse($cache->clear());
        $this->assertFalse($cache->setMultiple(['key' => 'value']));
        $this->assertFalse($cache->deleteMultiple(['key']));
    }
}
