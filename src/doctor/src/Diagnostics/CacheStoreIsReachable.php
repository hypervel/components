<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Cache\Console\CacheTableCommand;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Doctor\Support\EnvironmentConfiguration;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

class CacheStoreIsReachable extends Diagnostic implements Fixable
{
    public string $name = 'Cache connects';

    public string $group = 'cache';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default cache store configured.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach the default cache store [{store}].',
                remediation: 'Check CACHE_STORE and the backing cache service configuration.',
                confirmation: 'Which cache store should the application use?',
            )->link(Link::docs('cache', 'configuration')),
            'reachable' => 'The application can reach the default cache store.',
            'disabled' => 'The null cache store intentionally does not retain values.',
            'degraded' => Message::make(
                summary: 'The default cache store [{store}] can write and read, but its failover capacity is degraded.',
                remediation: 'Repair the unhealthy backing stores and check cache invalidation before relying on recovered stores.',
            )->link(Link::docs('cache', 'cache-failover')),
            'switched' => 'The default cache store was switched to [{store}].',
            'switch-failed' => 'The default cache store could not be switched.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $store = Configured::string('cache.default');

        if ($store === null) {
            return $this->skip('not-configured');
        }

        if ($store === 'null' || Configured::string("cache.stores.{$store}.driver") === 'null') {
            return $this->skip('disabled');
        }

        $failure = null;
        $cleaned = true;
        $alternatives = [];

        try {
            $alternatives = $this->failoverStores($store);
            $cleaned = $this->probe($store);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $unhealthy = [];

        foreach ($alternatives as $alternative) {
            try {
                if (! $this->probe($alternative)) {
                    $unhealthy[] = "[{$alternative}]: The probe key could not be removed.";
                }
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $unhealthy[] = "[{$alternative}]: {$exception->getMessage()}";
            }
        }

        if ($failure !== null || (! $cleaned && $unhealthy === [])) {
            $details = [$failure?->getMessage() ?? 'The probe key could not be removed.', ...$unhealthy];
            $result = $this->fail('unreachable', ['store' => $store])->withDetails(implode("\n", $details));

            // Building the option list probes candidate stores, so production
            // failures skip the work the fixable(Local) scope would discard.
            if (! EnvironmentMode::current()->isProduction() && ($options = $this->fixOptions($store)) !== []) {
                $result->fixable(EnvironmentMode::Local)->fixOptions(
                    $options,
                    decline: sprintf('Keep %s (repair it manually)', Str::headline($store)),
                );
            }

            return $result;
        }

        if ($unhealthy !== []) {
            if (! $cleaned) {
                $unhealthy[] = 'Cleanup could not be confirmed for the configured cache store. Stale values may reappear when backing stores recover.';
            }

            return $this->warn('degraded', ['store' => $store])->withDetails(implode("\n", $unhealthy));
        }

        return $this->pass('reachable');
    }

    /**
     * Fix the diagnostic by switching the application to the chosen store.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        /** @var string $option */
        $path = app()->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            return $this->fixFailed('switch-failed')
                ->withDetails(sprintf('The application environment file [%s] could not be updated.', $path));
        }

        if ($option === 'database' && $this->cacheTableIsMissing()) {
            $failure = $this->createCacheTable();

            if ($failure !== null) {
                return $failure;
            }
        }

        try {
            $this->ensureDatabaseTablesExist($option);

            if (! $this->probe($option)) {
                return $this->fixFailed('switch-failed')->withDetails('The probe key could not be removed.');
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            return $this->fixFailed('switch-failed')
                ->withDetails($e->getMessage());
        }

        Env::writeVariable('CACHE_STORE', $option, $path, overwrite: true);

        if (($failure = EnvironmentConfiguration::refresh('cache.default', $option)) !== null) {
            return $this->fixFailed('switch-failed')->withDetails($failure);
        }

        return $this->fixed('switched', ['store' => $option]);
    }

    /**
     * Get the viable alternative stores for the fix, keyed by store name.
     *
     * A candidate must be configured, use a different driver than the failing
     * store, and survive a probe. The database store is also offered when its
     * connection works but both default tables are missing, since the fix
     * creates the tables before switching.
     *
     * @return array<string, string>
     */
    private function fixOptions(string $failing): array
    {
        $failingDriver = Configured::string(sprintf('cache.stores.%s.driver', $failing));

        $options = [];

        foreach (['file' => 'File', 'database' => 'Database', 'redis' => 'Redis'] as $store => $label) {
            $driver = Configured::string(sprintf('cache.stores.%s.driver', $store));

            if ($driver === null || $driver === $failingDriver) {
                continue;
            }

            $needsTables = $store === 'database' && $this->cacheTableIsMissing();

            try {
                $this->ensureDatabaseTablesExist($store);

                if (! $this->probe($store)) {
                    continue;
                }
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable) {
                if (! $needsTables) {
                    continue;
                }
            }

            $options[$store] = $needsTables
                ? 'Database (creates cache tables and runs all pending migrations)'
                : $label;
        }

        return $options;
    }

    /**
     * Determine whether both tables in the default database cache layout are missing.
     */
    private function cacheTableIsMissing(): bool
    {
        try {
            $configuration = config('cache.stores.database');
            $defaultConnection = DB::getDefaultConnection();

            if (! is_array($configuration)
                || ($configuration['driver'] ?? null) !== 'database'
                || ($configuration['table'] ?? null) !== CacheTableCommand::TABLE
                || ($configuration['lock_table'] ?? DatabaseStore::DEFAULT_LOCK_TABLE) !== DatabaseStore::DEFAULT_LOCK_TABLE
                || ($configuration['connection'] ?? $defaultConnection) !== $defaultConnection
                || (($configuration['lock_connection'] ?? null) ?: $defaultConnection) !== $defaultConnection) {
                return false;
            }

            $schema = Schema::connection($defaultConnection);

            return ! $schema->hasTable(CacheTableCommand::TABLE)
                && ! $schema->hasTable(DatabaseStore::DEFAULT_LOCK_TABLE);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ensure a database candidate has both its data and lock tables.
     */
    private function ensureDatabaseTablesExist(string $store): void
    {
        $configuration = config()->array("cache.stores.{$store}");

        if ($configuration['driver'] !== 'database') {
            return;
        }

        $connection = $configuration['connection'] ?? null;
        $lockConnection = ($configuration['lock_connection'] ?? null) ?: $connection;
        $lockTable = $configuration['lock_table'] ?? DatabaseStore::DEFAULT_LOCK_TABLE;

        if (! Schema::connection($connection)->hasTable($configuration['table'])
            || ! Schema::connection($lockConnection)->hasTable($lockTable)) {
            throw new RuntimeException('The database cache requires both its data and lock tables. Repair the missing tables before selecting this store.');
        }
    }

    /**
     * Create the database store's cache and lock tables.
     */
    private function createCacheTable(): ?FixResult
    {
        foreach (['make:cache-table' => [], 'migrate' => ['--force' => true]] as $command => $parameters) {
            if (Artisan::call($command, $parameters) !== 0) {
                return $this->fixFailed('switch-failed')
                    ->withDetails(trim(Artisan::output()));
            }
        }

        return null;
    }

    /**
     * Probe a cache store with a short-lived key.
     */
    private function probe(string $store): bool
    {
        $repository = Cache::store($store);
        $key = $this->key();
        $failure = null;
        $cleaned = false;

        try {
            if (! $repository->put($key, true, 10)) {
                throw new RuntimeException('The cache store did not accept the probe value.');
            }

            if ($repository->get($key) !== true) {
                throw new RuntimeException('The cache store did not return the probe value.');
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            try {
                $cleaned = $repository->forget($key);
            } catch (CanceledException $exception) {
                if (! $failure instanceof CanceledException) {
                    $failure = $exception;
                }
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $cleaned;
    }

    /**
     * Find failover alternatives without separately probing ordinary stack layers.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    private function failoverStores(string $store, array $seen = []): array
    {
        if (in_array($store, $seen, true)) {
            return [];
        }

        $driver = Configured::string("cache.stores.{$store}.driver");

        if (! in_array($driver, ['stack', 'failover'], true)) {
            return [];
        }

        $stores = config()->array("cache.stores.{$store}.stores");
        $alternatives = [];

        foreach ($stores as $name => $options) {
            $nested = $driver === 'stack' && is_array($options) ? $name : $options;

            if ($driver === 'stack') {
                $nested = $nested instanceof UnitEnum ? (string) enum_value($nested) : $nested;
                $nested ??= config()->string('cache.default');
            }

            if ($nested === 'null' || Configured::string("cache.stores.{$nested}.driver") === 'null') {
                continue;
            }

            if ($driver === 'failover') {
                $alternatives[] = $nested;
            }

            array_push($alternatives, ...$this->failoverStores($nested, [...$seen, $store]));
        }

        return array_values(array_unique($alternatives));
    }

    /**
     * Get a temporary cache key.
     */
    private function key(): string
    {
        return 'hypervel-doctor:' . str_replace('.', '', uniqid('', true));
    }
}
