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
     * Validate a store and every nested stack layer.
     *
     * @param list<int> $layerPath
     *
     * @throws UnsupportedModelCacheStoreException
     */
    private function validateStore(Store $store, string $feature, array $layerPath = []): void
    {
        if ($store instanceof StackStore) {
            foreach ($store->getStores() as $index => $proxy) {
                $this->validateStore(
                    $proxy->getStore(),
                    $feature,
                    [...$layerPath, $index],
                );
            }

            return;
        }

        if ($store instanceof RedisStore) {
            $this->validateRedisStore($store, $feature, $layerPath);

            return;
        }

        if ($store instanceof DatabaseStore
            || $store instanceof FileStore
            || $store instanceof StorageStore
            || $store instanceof SwooleStore) {
            return;
        }

        throw new UnsupportedModelCacheStoreException(sprintf(
            '%s does not support cache store [%s]%s.',
            $feature,
            $store::class,
            $this->stackLocation($layerPath),
        ));
    }

    /**
     * Validate that a Redis serializer preserves model objects.
     *
     * @param list<int> $layerPath
     *
     * @throws UnsupportedModelCacheStoreException
     */
    private function validateRedisStore(RedisStore $store, string $feature, array $layerPath): void
    {
        $connection = $store->getContext()->connectionName();
        /** @var array<array-key, mixed> $options */
        $options = $this->redisConfig->connectionConfig($connection)['options'] ?? [];
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
                $layerPath,
                'msgpack.php_only=1 is required to preserve model objects',
            );
        }

        if ($serializer === Redis::SERIALIZER_JSON) {
            $this->rejectRedisSerializer(
                $feature,
                $connection,
                $serializer,
                $layerPath,
                'the JSON serializer converts model objects to arrays',
            );
        }

        $this->rejectRedisSerializer(
            $feature,
            $connection,
            $serializer,
            $layerPath,
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
     * @param list<int> $layerPath
     *
     * @throws UnsupportedModelCacheStoreException
     */
    private function rejectRedisSerializer(
        string $feature,
        string $connection,
        int $serializer,
        array $layerPath,
        string $reason,
    ): never {
        throw new UnsupportedModelCacheStoreException(sprintf(
            '%s does not support Redis cache connection [%s] with serializer [%d]%s because %s.',
            $feature,
            $connection,
            $serializer,
            $this->stackLocation($layerPath),
            $reason,
        ));
    }

    /**
     * Format a nested stack layer location.
     *
     * @param list<int> $layerPath
     */
    private function stackLocation(array $layerPath): string
    {
        return $layerPath === []
            ? ''
            : sprintf(' at stack layer [%s]', implode('.', $layerPath));
    }
}
