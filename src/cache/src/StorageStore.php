<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Exception;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Support\InteractsWithTime;
use Swoole\Coroutine\CanceledException;

class StorageStore implements Store
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

    /**
     * The expiration timestamp stored for items cached forever.
     */
    protected const int PERMANENT_TIMESTAMP = 9999999999;

    /**
     * The filesystem disk instance.
     */
    protected Filesystem $disk;

    /**
     * The storage path where cache files should be written.
     */
    protected string $directory;

    /**
     * A string that should be prepended to keys.
     */
    protected string $prefix;

    /**
     * The classes that should be allowed during unserialization.
     */
    protected array|bool|null $serializableClasses;

    /**
     * The shared serializable class policy.
     */
    protected ?SerializableClassPolicy $serializableClassPolicy;

    /**
     * Create a new storage cache store instance.
     */
    public function __construct(
        Filesystem $disk,
        string $directory = '',
        string $prefix = '',
        array|bool|null $serializableClasses = null,
        ?SerializableClassPolicy $serializableClassPolicy = null,
    ) {
        $this->disk = $disk;
        $this->directory = trim($directory, '/');
        $this->prefix = $prefix;
        $this->serializableClasses = $serializableClasses;
        $this->serializableClassPolicy = $serializableClassPolicy;
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        return $this->getPayload($key)['data'] ?? null;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->putWithExpiresAt($key, $value, $this->expiration($seconds));
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        if (! is_null($this->get($key))) {
            return false;
        }

        return $this->put($key, $value, $seconds);
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        $raw = $this->getPayload($key);
        $expiresAt = $raw['expiresAt'] ?? null;

        return tap(((int) $raw['data']) + $value, function (int $newValue) use ($key, $raw, $expiresAt): void {
            if ($expiresAt === null) {
                $this->put($key, $newValue, $raw['time'] ?? 0);

                return;
            }

            $this->putWithExpiresAt($key, $newValue, $expiresAt);
        });
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        $payload = $this->getPayload($key);

        if (is_null($payload['data'])) {
            return false;
        }

        return $this->put($key, $payload['data'], $seconds);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        $forgotten = $this->disk->delete($this->path($key));

        if ($forgotten) {
            $this->disk->delete($this->path(Repository::FLEXIBLE_CREATED_KEY_PREFIX . $key));
        }

        return $forgotten;
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        if ($this->directory === '') {
            $files = $this->disk->allFiles();

            return $files === [] || $this->disk->delete($files);
        }

        return $this->disk->deleteDirectory($this->directory)
            && $this->disk->makeDirectory($this->directory);
    }

    /**
     * Store an item with an absolute expiration timestamp.
     */
    protected function putWithExpiresAt(string $key, mixed $value, int $expiresAt): bool
    {
        return $this->disk->put(
            $this->path($key),
            $this->expiresAtHeader($expiresAt) . serialize($value)
        ) !== false;
    }

    /**
     * Retrieve an item and expiry time from the cache by key.
     *
     * @return array{data: mixed, time: ?int, expiresAt: ?int}
     */
    protected function getPayload(string $key): array
    {
        $path = $this->path($key);

        try {
            if (is_null($contents = $this->disk->get($path))) {
                return $this->emptyPayload();
            }

            $expiresAt = (int) substr($contents, 0, 10);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Exception) {
            return $this->emptyPayload();
        }
        $currentTime = $this->currentTime();

        if ($currentTime >= $expiresAt) {
            $this->forget($key);

            return $this->emptyPayload();
        }

        try {
            $data = $this->unserialize(substr($contents, 10));
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Exception) {
            $this->forget($key);

            return $this->emptyPayload();
        }

        // Keep Laravel's remaining duration for subclasses; internal rewrites use the exact deadline.
        $time = $expiresAt - $currentTime;

        return compact('data', 'time', 'expiresAt');
    }

    /**
     * Unserialize the given value.
     */
    protected function unserialize(string $value): mixed
    {
        if ($this->serializableClassPolicy !== null) {
            return $this->serializableClassPolicy->unserialize($value);
        }

        if ($this->serializableClasses !== null) {
            return unserialize($value, ['allowed_classes' => $this->serializableClasses]);
        }

        return unserialize($value);
    }

    /**
     * Get a default empty payload for the cache.
     *
     * @return array{data: mixed, time: ?int, expiresAt: ?int}
     */
    protected function emptyPayload(): array
    {
        return ['data' => null, 'time' => null, 'expiresAt' => null];
    }

    /**
     * Get the full path for the given cache key.
     */
    public function path(string $key): string
    {
        $parts = array_slice(str_split($hash = hash('xxh128', $this->prefix . $key), 2), 0, 2);

        return trim($this->directory . '/' . implode('/', $parts) . '/' . $hash, '/');
    }

    /**
     * Get the expiration time based on the given seconds.
     */
    protected function expiration(int $seconds): int
    {
        $time = $this->availableAt($seconds);

        return $seconds === 0 || $time > self::PERMANENT_TIMESTAMP ? self::PERMANENT_TIMESTAMP : $time;
    }

    /**
     * Get the fixed-width expiration header for a cache item.
     */
    protected function expiresAtHeader(int $expiresAt): string
    {
        return sprintf('%010d', $expiresAt);
    }

    /**
     * Get the filesystem disk instance.
     */
    public function getDisk(): Filesystem
    {
        return $this->disk;
    }

    /**
     * Get the working directory of the cache.
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Set the cache key prefix.
     *
     * Boot-only. Persists on the cached store for the worker lifetime;
     * per-request use races across coroutines.
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }
}
