<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client;

use Closure;
use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Contracts\Transport;
use Hypervel\Mcp\Enums\CacheScope;
use Hypervel\Mcp\Server;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Cache;

class ResponseCache
{
    public const int MAX_TTL_MS = 86_400_000;

    /**
     * Create a response cache for the given store and private scope.
     */
    public function __construct(
        public readonly ?string $store = null,
        public readonly ?string $for = null,
    ) {
    }

    /**
     * Retrieve a cached response or remember an eligible server result.
     *
     * @param Method<mixed> $method
     * @param Closure(): array<string, mixed> $fetch
     * @return array<string, mixed>
     */
    public function remember(Method $method, Transport $transport, Closure $fetch): array
    {
        $params = $method->params();

        ksort($params);

        if (! $this->cacheable($method->method(), $params)) {
            return $fetch();
        }

        $recipe = $transport->recipe();
        $repository = Cache::store($this->store);
        $shared = $this->key($recipe, $method->method(), $params, CacheScope::Public);
        $private = $this->key($recipe, $method->method(), $params, CacheScope::Private);

        $cached = $repository->get($private) ?? $repository->get($shared);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $fetch();

        if (Arr::get($result, 'resultType', 'complete') !== 'complete' || filled(Arr::get($result, 'nextCursor'))) {
            return $result;
        }

        $ttlMs = min((int) (Arr::get($result, 'ttlMs') ?? 0), self::MAX_TTL_MS);

        if ($ttlMs > 0) {
            $repository->put(
                Arr::get($result, 'cacheScope') === CacheScope::Public->value ? $shared : $private,
                $result,
                now()->addMilliseconds($ttlMs),
            );
        }

        return $result;
    }

    /**
     * Determine whether the request permits response caching.
     *
     * @param array<string, mixed> $params
     */
    protected function cacheable(string $method, array $params): bool
    {
        return in_array($method, Server::CACHEABLE_METHODS, true)
            && ! isset($params['cursor'])
            && ! isset($params['inputResponses'])
            && ! isset($params['requestState']);
    }

    /**
     * Build a cache key for the endpoint, request and scope.
     *
     * @param array<string, mixed> $recipe
     * @param array<string, mixed> $params
     */
    protected function key(array $recipe, string $method, array $params, CacheScope $scope): string
    {
        return implode(':', [
            'mcp',
            $this->hash(Arr::only($recipe, ['driver', 'url', 'command', 'args', 'headers'])),
            $scope === CacheScope::Public
                ? 'public'
                : $this->hash([$this->for, Arr::except($recipe, ['timeoutSeconds'])]),
            $method,
            $this->hash($params),
        ]);
    }

    /**
     * Hash cache inputs, which may include credentials.
     */
    protected function hash(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }
}
