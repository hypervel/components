<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Exceptions\UnsupportedModelCacheStoreException;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Redis\RedisConfig;
use Redis;

class ModelCacheStoreValidator
{
    /**
     * Create a model cache store validator.
     */
    public function __construct(
        private readonly RedisConfig $redisConfig,
    ) {
    }

    /**
     * Validate that the store can safely cache models.
     *
     * @throws UnsupportedModelCacheStoreException
     */
    public function validate(CacheRepository $repository, string $feature): void
    {
        $this->validateStore($repository->getStore(), $feature);
    }

    /**
     * Validate that the store supports plain-key reads with any-mode tags.
     *
     * Any-mode tags index plain cache keys without changing their storage
     * keys. Model caches can therefore keep direct reads and per-key
     * invalidation on the plain repository while tagged writes maintain
     * their indexes.
     *
     * @throws UnsupportedModelCacheStoreException
     */
    public function validateAnyModeTags(CacheRepository $repository, string $feature): void
    {
        $store = $repository->getStore();

        if (! $store instanceof TaggableStore || ! $store->supportsTags()) {
            throw new UnsupportedModelCacheStoreException(sprintf(
                '%s cannot use tags with cache store [%s] because the store does not support tags.',
                $feature,
                $store::class,
            ));
        }

        $mode = $store->getTagMode();

        if ($mode !== TagMode::Any) {
            throw new UnsupportedModelCacheStoreException(sprintf(
                '%s cannot use tags with cache store [%s] in mode [%s] because TagMode::Any is required.',
                $feature,
                $store::class,
                $mode->value,
            ));
        }
    }

    /**
     * Validate a model cache store.
     *
     * @throws UnsupportedModelCacheStoreException
     */
    private function validateStore(Store $store, string $feature): void
    {
        if ($store instanceof StackStore) {
            throw new UnsupportedModelCacheStoreException(sprintf(
                '%s cannot use cache stack [%s] because an upper layer can retain an identity cache entry in another worker or node after invalidation.',
                $feature,
                $store::class,
            ));
        }

        if ($store instanceof RedisStore) {
            $this->validateRedisStore($store, $feature);
        } elseif (! ($store instanceof DatabaseStore
            || $store instanceof FileStore
            || $store instanceof SwooleStore)) {
            throw new UnsupportedModelCacheStoreException(sprintf(
                '%s does not support cache store [%s].',
                $feature,
                $store::class,
            ));
        }
    }

    /**
     * Validate that a Redis serializer preserves model objects.
     *
     * @throws UnsupportedModelCacheStoreException
     */
    private function validateRedisStore(RedisStore $store, string $feature): void
    {
        $connection = $store->getContext()->connectionName();
        /** @var array<array-key, mixed> $options */
        $options = $this->redisConfig->connectionConfig($connection)['options'];
        $serializer = Redis::SERIALIZER_NONE;

        foreach ($options as $option => $value) {
            if ((is_string($option) && strtolower($option) === 'serializer')
                || $option === Redis::OPT_SERIALIZER) {
                $serializer = (int) $value;
            }
        }

        if ($serializer === Redis::SERIALIZER_NONE
            || $serializer === Redis::SERIALIZER_PHP
            || $this->isSerializer($serializer, 'SERIALIZER_IGBINARY')) {
            return;
        }

        if ($this->isSerializer($serializer, 'SERIALIZER_MSGPACK')) {
            if (filter_var(ini_get('msgpack.php_only'), FILTER_VALIDATE_BOOL) === true) {
                return;
            }

            $this->rejectRedisSerializer(
                $feature,
                $connection,
                $serializer,
                'msgpack.php_only=1 is required to preserve model objects',
            );
        }

        if ($serializer === Redis::SERIALIZER_JSON) {
            $this->rejectRedisSerializer(
                $feature,
                $connection,
                $serializer,
                'the JSON serializer converts model objects to arrays',
            );
        }

        $this->rejectRedisSerializer(
            $feature,
            $connection,
            $serializer,
            'the serializer is not verified to preserve model objects',
        );
    }

    /**
     * Determine whether a build-dependent Redis serializer matches.
     */
    private function isSerializer(int $serializer, string $constant): bool
    {
        $name = Redis::class . '::' . $constant;

        return defined($name) && $serializer === constant($name);
    }

    /**
     * Throw an unsupported Redis serializer exception.
     *
     * @throws UnsupportedModelCacheStoreException
     */
    private function rejectRedisSerializer(
        string $feature,
        string $connection,
        int $serializer,
        string $reason,
    ): never {
        throw new UnsupportedModelCacheStoreException(sprintf(
            '%s does not support Redis cache connection [%s] with serializer [%d] because %s.',
            $feature,
            $connection,
            $serializer,
            $reason,
        ));
    }
}
