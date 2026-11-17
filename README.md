# PSR-16 Simple Cache

A [PSR-16](https://www.php-fig.org/psr/psr-16/) compliant caching library for PHP 8.3+, built around a swappable storage driver architecture.

## Tech Stack

- **PHP** 8.3+
- **PSR-16** (`psr/simple-cache: ^3.0`) — `CacheInterface` contract
- **Drivers** — `ArrayStorage` (in-memory), `FileStorage` (file-system)

---

## Installation

```bash
composer require gadwinn/psr-simple-cache
```

---

## Usage

### Instantiation

```php
use G\SimpleCache\Cache;
use G\SimpleCache\Storage\ArrayStorage;
use G\SimpleCache\Storage\FileStorage;

// In-memory (default) — no persistence across requests
$cache = new Cache();

// In-memory with a default TTL of 300 seconds
$cache = new Cache(new ArrayStorage(), defaultTtl: 300);

// File-system — persists across requests
$cache = new Cache(new FileStorage('/var/cache/myapp'));

// File-system with a default TTL
$cache = new Cache(new FileStorage('/var/cache/myapp'), defaultTtl: 3600);
```

The constructor signature:

```php
public function __construct(
    StorageInterface $storage = new ArrayStorage(),
    ?int $defaultTtl = null
)
```

`defaultTtl` applies whenever `set()` or `setMultiple()` is called without an explicit TTL. `null` means cache forever.

---

### Basic read / write

```php
$cache->set('user.42', $userObject);

$user = $cache->get('user.42');           // returns the value
$user = $cache->get('missing', $default); // returns $default on miss

$cache->has('user.42'); // bool

$cache->delete('user.42');
$cache->clear();
```

---

### TTL

Accepts an `int` (seconds) or a `DateInterval`:

```php
// Expires in 10 minutes
$cache->set('token', $jwt, 600);

// Expires via DateInterval
$cache->set('token', $jwt, new DateInterval('PT10M'));

// A zero or negative TTL deletes the item immediately (PSR-16 §1.2)
$cache->set('stale_key', $value, -1);
```

---

### Batch operations

```php
// Write multiple entries (same TTL for all)
$cache->setMultiple([
    'config.db'    => $dbConfig,
    'config.cache' => $cacheConfig,
], ttl: 3600);

// Read multiple entries with a shared default
$results = $cache->getMultiple(['config.db', 'config.cache'], default: []);

// Delete multiple entries
$cache->deleteMultiple(['config.db', 'config.cache']);
```

---

### Key rules

A key is any non-empty string that contains none of the characters reserved by PSR-16: `{}()/\@:`. There is no length limit. In `getMultiple()`, `setMultiple()` and `deleteMultiple()`, integer keys are accepted and cast to strings (PHP turns numeric string array keys into integers), and every key is validated before anything is written or deleted.  
Any invalid key throws `G\SimpleCache\InvalidArgumentException` (implements `Psr\SimpleCache\InvalidArgumentException`).

---

### Custom storage driver

Implement `G\SimpleCache\Storage\StorageInterface`:

```php
use G\SimpleCache\Storage\StorageInterface;

class RedisStorage implements StorageInterface
{
    public function __construct(private readonly \Redis $redis) {}

    public function read(string $key): ?array
    {
        $raw = $this->redis->get($key);
        if ($raw === false) {
            return null;
        }
        $data = unserialize($raw, ['allowed_classes' => true]);
        return is_array($data) ? $data : null;
    }

    public function write(string $key, mixed $value, ?int $expiresAt): void
    {
        $ttl = $expiresAt !== null ? max(1, $expiresAt - time()) : 0;
        $this->redis->set($key, serialize(compact('value', 'expiresAt')), $ttl);
    }

    public function remove(string $key): void
    {
        $this->redis->del($key);
    }

    public function flush(): void
    {
        $this->redis->flushDB();
    }
}

$cache = new Cache(new RedisStorage($redis));
```

A driver reports a failed write, removal or flush by throwing any exception: `Cache` turns it into the `false` result required by PSR-16 (`set()`, `delete()`, `clear()`, `setMultiple()`, `deleteMultiple()`). Removing a missing entry is not a failure.

`FileStorage` writes each entry to a temporary file and renames it, so a concurrent reader never sees a partial file; an unreadable or corrupted file is a cache miss.

The interface contract:

```php
interface StorageInterface
{
    /** @return array{value: mixed, expiresAt: int|null}|null */
    public function read(string $key): ?array;

    /** @param int|null $expiresAt Absolute Unix timestamp, or null for no expiry. */
    public function write(string $key, mixed $value, ?int $expiresAt): void;

    public function remove(string $key): void;
    public function flush(): void;
}
```


## License

MIT, see [LICENSE](LICENSE).
