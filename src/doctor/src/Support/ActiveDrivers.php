<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

use Hypervel\Session\SessionManager;
use Hypervel\Support\Arr;
use Hypervel\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\enum_value;

final class ActiveDrivers
{
    /**
     * Get the concrete stores used by a cache store.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    public static function cacheStores(string $store, array $seen = []): array
    {
        if (($cycleStart = array_search($store, $seen, true)) !== false) {
            throw new InvalidArgumentException('Circular cache store definition detected: ' . implode(' -> ', [...array_slice($seen, $cycleStart), $store]) . '.');
        }

        $driver = config("cache.stores.{$store}.driver");

        if (! in_array($driver, ['stack', 'failover'], true)) {
            return [$store];
        }

        $active = [];

        foreach (config()->array("cache.stores.{$store}.stores") as $name => $configuration) {
            $nested = $driver === 'stack' && is_array($configuration) ? $name : $configuration;

            if ($driver === 'stack') {
                $nested = $nested instanceof UnitEnum ? (string) enum_value($nested) : $nested;
                $nested ??= config()->string('cache.default');
            }

            $active = [...$active, ...self::cacheStores($nested, [...$seen, $store])];
        }

        return array_values(array_unique($active));
    }

    /**
     * Get the concrete channels used by a logging channel.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    public static function logChannels(string $channel, array $seen = []): array
    {
        $channel = trim($channel);

        if (in_array($channel, $seen, true)) {
            return [];
        }

        if (config("logging.channels.{$channel}.driver") !== 'stack') {
            return [$channel];
        }

        $channels = config("logging.channels.{$channel}.channels");

        if (is_string($channels)) {
            $channels = explode(',', $channels);
        }

        if (! is_array($channels)) {
            return [];
        }

        $active = [];

        foreach ($channels as $nested) {
            $nested = $nested instanceof UnitEnum ? (string) enum_value($nested) : $nested;

            if (is_string($nested) && $nested !== '') {
                $active = [...$active, ...self::logChannels($nested, [...$seen, $channel])];
            }
        }

        return array_values(array_unique($active));
    }

    /**
     * Get the concrete mailers used by a mailer.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    public static function mailers(string $mailer, array $seen = []): array
    {
        if (in_array($mailer, $seen, true)) {
            return [];
        }

        $configuration = self::mailerConfiguration($mailer);
        $transport = $configuration['transport'] ?? $mailer;

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return [$mailer];
        }

        $mailers = $configuration['mailers'] ?? null;

        if (! is_array($mailers)) {
            return [];
        }

        $active = [];

        foreach ($mailers as $nested) {
            if (is_string($nested) && $nested !== '') {
                $active = [...$active, ...self::mailers($nested, [...$seen, $mailer])];
            }
        }

        return array_values(array_unique($active));
    }

    /**
     * Get the mailer configuration after applying its connection URL.
     *
     * @return array<string, mixed>
     */
    public static function mailerConfiguration(string $mailer): array
    {
        $configuration = config("mail.mailers.{$mailer}");

        if (! is_array($configuration)) {
            return [];
        }

        if (isset($configuration['url'])) {
            $configuration = array_merge($configuration, (new ConfigurationUrlParser)->parseConfiguration($configuration));
            $configuration['transport'] = Arr::pull($configuration, 'driver');
        }

        return $configuration;
    }

    /**
     * Get the concrete connections used by a queue connection.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    public static function queueConnections(string $connection, array $seen = []): array
    {
        if (($cycleStart = array_search($connection, $seen, true)) !== false) {
            throw new InvalidArgumentException('Circular queue connection definition detected: ' . implode(' -> ', [...array_slice($seen, $cycleStart), $connection]) . '.');
        }

        if (config("queue.connections.{$connection}.driver") !== 'failover') {
            return [$connection];
        }

        $active = [];

        foreach (config()->array("queue.connections.{$connection}.connections") as $nested) {
            $nested = $nested instanceof UnitEnum ? (string) enum_value($nested) : $nested;
            $nested = $nested === null || $nested === '' ? config()->string('queue.default') : $nested;
            $active = [...$active, ...self::queueConnections($nested, [...$seen, $connection])];
        }

        return array_values(array_unique($active));
    }

    /**
     * Get the Redis connections used by active cache, queue, and session drivers.
     *
     * @return list<string>
     */
    public static function redisConnections(): array
    {
        $connections = [];
        $cache = Configured::string('cache.default');

        foreach ($cache === null ? [] : self::cacheStores($cache) as $store) {
            $configuration = config("cache.stores.{$store}");

            if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'redis') {
                continue;
            }

            $connection = $configuration['connection'] ?? 'default';
            $connections[] = $connection;
            $connections[] = $configuration['lock_connection'] ?? $connection;
        }

        $queue = Configured::string('queue.default');

        foreach ($queue === null ? [] : self::queueConnections($queue) as $name) {
            $configuration = config("queue.connections.{$name}");

            if (is_array($configuration) && ($configuration['driver'] ?? null) === 'redis') {
                $connections[] = $configuration['connection'] ?? 'default';
            }
        }

        if (config('session.driver') === 'redis') {
            $connections[] = Configured::string('session.connection', SessionManager::DEFAULT_REDIS_CONNECTION);
        }

        return array_values(array_unique($connections));
    }
}
