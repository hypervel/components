<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Closure;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Coordinate authentication lookups and invalidation across model and query writes.
 */
class PassportCache
{
    protected const string CONTEXT_KEY_PREFIX = '__passport.cache.';

    protected const int MUTATION_BATCH_SIZE = 1000;

    /**
     * Create the Passport cache.
     */
    public function __construct(
        protected CacheFactory $cache,
        protected ConfigRepository $config,
        protected ModelCacheCoordinator $coordinator,
    ) {
    }

    /**
     * Find an access token for authentication.
     *
     * @template TToken of Token
     * @param class-string<TToken> $model
     * @return null|TToken
     */
    public function findToken(string $model, string $id): ?Token
    {
        // Authentication must see new credentials and revocations despite replica lag.
        return $this->remember(
            'tokens',
            $id,
            static fn (): Token => new $model,
            static fn (): ?Token => $model::query()->useWritePdo()->find($id)?->unsetRelations(),
        );
    }

    /**
     * Find a client for authentication.
     *
     * @param class-string<Client> $model
     * @param Closure(): (null|Client) $read
     */
    public function findClient(string $model, string|int $id, Closure $read): ?Client
    {
        return $this->remember(
            'clients',
            (string) $id,
            static fn (): Client => new $model,
            $read,
        );
    }

    /**
     * Retrieve a model through the request memo and optional shared cache.
     *
     * @template TModel of Model
     * @param Closure(): TModel $source
     * @param Closure(): (null|TModel) $read
     * @return null|TModel
     */
    protected function remember(string $type, string $id, Closure $source, Closure $read): ?Model
    {
        if ($this->coordinator->hasApplicableTransaction($source)) {
            return $read();
        }

        $contextKey = self::CONTEXT_KEY_PREFIX . $type;
        $models = RequestContext::getValue($contextKey, []);

        if (array_key_exists($id, $models)) {
            return $models[$id];
        }

        $model = $this->config->boolean('passport.cache.enabled')
            ? $this->coordinator->fill(
                $this->getCache(),
                $source,
                $this->getCacheKey($type, $id),
                $this->config->integer('passport.cache.ttl'),
                $read,
                cacheNull: false,
            )
            : $read();

        // Model retrieval callbacks may have memoized other credentials while this read ran.
        $models = RequestContext::getValue($contextKey, []);
        $models[$id] = $model;
        RequestContext::setValue($contextKey, $models);

        return $model;
    }

    /**
     * Clear an access token's memo and settle its shared invalidation.
     */
    public function forgetToken(string $id, Connection $connection): void
    {
        $this->forgetMany('tokens', [$id], $connection);
    }

    /**
     * Clear a client's memo and settle its shared invalidation.
     */
    public function forgetClient(string $id, Connection $connection): void
    {
        $this->forgetMany('clients', [$id], $connection);
    }

    /**
     * Clear a newly created token's missing request memo.
     */
    public function forgetTokenMemo(string $id): void
    {
        $this->forgetMemo('tokens', [$id]);
    }

    /**
     * Clear a newly created client's missing request memo.
     */
    public function forgetClientMemo(string $id): void
    {
        $this->forgetMemo('clients', [$id]);
    }

    /**
     * Forget credentials without publishing transaction-local state.
     *
     * @param array<int, int|string> $ids
     */
    protected function forgetMany(string $type, array $ids, Connection $connection): void
    {
        $this->forgetMemo($type, $ids);

        if (! $this->config->boolean('passport.cache.enabled')) {
            return;
        }

        $cache = $this->getCache();
        $connection->afterCommitOrNow(function () use ($cache, $type, $ids): void {
            $exception = null;

            // The commit is irreversible, so one failure must not discard other invalidations.
            foreach ($ids as $id) {
                try {
                    $this->coordinator->invalidate($cache, $this->getCacheKey($type, (string) $id));
                } catch (CanceledException $canceledException) {
                    throw $canceledException;
                } catch (Throwable $throwable) {
                    $exception ??= $throwable;
                }
            }

            if ($exception !== null) {
                throw $exception;
            }
        });
    }

    /**
     * Clear credentials from the current request's memo.
     *
     * @param array<int, int|string> $ids
     */
    protected function forgetMemo(string $type, array $ids): void
    {
        $contextKey = self::CONTEXT_KEY_PREFIX . $type;
        $models = RequestContext::getValue($contextKey, []);

        foreach ($ids as $id) {
            unset($models[$id]);
        }

        RequestContext::setValue($contextKey, $models);
    }

    /**
     * Update related credentials and invalidate exactly the selected rows.
     *
     * @param Builder<Client>|Builder<Token> $query
     * @param array<string, mixed> $values
     */
    public function update(Builder $query, array $values): int
    {
        return $this->mutate($query, static fn (Builder $batch): int => $batch->update($values));
    }

    /**
     * Delete related credentials and invalidate exactly the selected rows.
     *
     * @param Builder<Client>|Builder<Token> $query
     */
    public function delete(Builder $query): int
    {
        return $this->mutate($query, static fn (Builder $batch): int => $batch->delete());
    }

    /**
     * Apply a relation mutation in bounded primary-key batches.
     *
     * @param Builder<Client>|Builder<Token> $query
     * @param Closure(Builder): int $write
     */
    protected function mutate(Builder $query, Closure $write): int
    {
        $model = $query->getModel();
        $type = $model instanceof Token ? 'tokens' : 'clients';

        if (! $this->config->boolean('passport.cache.enabled')) {
            RequestContext::forgetValue(self::CONTEXT_KEY_PREFIX . $type);

            return $write($query);
        }

        $key = $model->getKeyName();
        $qualifiedKey = $model->getQualifiedKeyName();
        $connection = $model->getConnection();

        // Batching must preserve the atomicity of the uncached, single-statement write.
        return $connection->transaction(function () use ($query, $write, $type, $key, $qualifiedKey, $connection): int {
            $affected = 0;

            (clone $query)->select($qualifiedKey)->useWritePdo()->toBase()->chunkById(
                self::MUTATION_BATCH_SIZE,
                function (Collection $rows) use ($query, $write, $type, $key, $connection, &$affected): void {
                    $ids = $rows->pluck($key)->all();
                    $affected += $write((clone $query)->whereKey($ids));

                    $this->forgetMany($type, $ids, $connection);
                },
                $qualifiedKey,
                $key,
            );

            return $affected;
        });
    }

    /**
     * Get the configured shared cache repository.
     */
    protected function getCache(): CacheRepository
    {
        $store = $this->config->get('passport.cache.store');

        return $this->cache->store($store === '' ? null : $store);
    }

    /**
     * Build a shared cache key for a credential.
     */
    protected function getCacheKey(string $type, string $id): string
    {
        return $this->config->string('passport.cache.prefix') . ':' . $type . ':' . $id;
    }
}
