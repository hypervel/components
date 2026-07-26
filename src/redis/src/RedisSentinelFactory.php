<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use RedisSentinel;
use Throwable;

class RedisSentinelFactory
{
    /**
     * Create a redis sentinel client instance.
     *
     * @param array<string, mixed> $options
     */
    public function create(array $options = []): RedisSentinel
    {
        // https://github.com/phpredis/phpredis/blob/develop/sentinel.md#examples-for-version-60-or-later
        return new RedisSentinel($options); /* @phpstan-ignore-line */
    }

    /**
     * Resolve the current master address.
     *
     * @param array<string, mixed> $config
     * @return array{0: string, 1: int}
     */
    public function resolveMaster(array $config): array
    {
        $sentinel = $config['sentinel'] ?? [];
        $nodes = $sentinel['nodes'] ?? [];
        $failures = [];

        shuffle($nodes);

        foreach ($nodes as $node) {
            $hasScheme = str_contains($node, '://');
            $resolved = parse_url($hasScheme ? $node : "tcp://{$node}");

            if (! is_array($resolved)
                || ! isset($resolved['host'], $resolved['port'])) {
                $failures[] = "[{$node}]: invalid node";
                continue;
            }

            if (array_diff_key($resolved, ['scheme' => true, 'host' => true, 'port' => true]) !== []) {
                $failures[] = "[{$node}]: unsupported node format";
                continue;
            }

            if (str_contains($resolved['host'], ':')
                && ! str_starts_with($resolved['host'], '[')) {
                $failures[] = "[{$node}]: IPv6 node addresses must be bracketed, for example [::1]:26379";
                continue;
            }

            try {
                $options = [
                    'host' => $hasScheme
                        ? "{$resolved['scheme']}://{$resolved['host']}"
                        : $resolved['host'],
                    'port' => (int) $resolved['port'],
                    'connectTimeout' => (float) ($config['timeout'] ?? 0),
                    'persistent' => $sentinel['persistent'] ?? null,
                    'retryInterval' => (int) ($config['retry_interval'] ?? 0),
                    'readTimeout' => (float) ($sentinel['read_timeout'] ?? 0),
                ];
                $context = $sentinel['context'] ?? [];
                $auth = $sentinel['auth'] ?? null;

                if ($context !== []) {
                    $options['ssl'] = $this->normalizeContext($context);
                }

                if ($auth !== null && $auth !== '') {
                    $options['auth'] = $auth;
                }

                $master = $this->create($options)->getMasterAddrByName(
                    (string) ($sentinel['master_name'] ?? '')
                );

                if (is_array($master)
                    && isset($master[0], $master[1])
                    && is_string($master[0])
                    && $master[0] !== ''
                    && (is_int($master[1]) || (is_string($master[1]) && ctype_digit($master[1])))) {
                    return [$master[0], (int) $master[1]];
                }

                $failures[] = "[{$node}]: master was not resolved";
            } catch (Throwable $exception) {
                $failures[] = "[{$node}]: {$exception->getMessage()}";
            }
        }

        throw new InvalidRedisConnectionException(sprintf(
            'Unable to resolve Redis master [%s] from Sentinel nodes: %s.',
            $sentinel['master_name'] ?? '',
            implode('; ', $failures),
        ));
    }

    /**
     * Normalize the SSL context for a Sentinel connection.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        if (isset($context['ssl']) && is_array($context['ssl'])) {
            return $context['ssl'];
        }

        if (isset($context['stream']) && is_array($context['stream'])) {
            return $context['stream'];
        }

        return $context;
    }
}
