<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Concerns;

use Closure;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Testbench\Foundation\Env;

trait HandlesDatabaseConnections
{
    /**
     * Allow database connection settings to be overridden by driver-specific env vars.
     */
    final protected function usesDatabaseConnectionsEnvironmentVariables(Repository $config, string $driver, string $keyword): void
    {
        $keyword = Str::upper($keyword);

        /** @var array<string, array{env: array<int, string>|string, rules?: null|(Closure(mixed): bool)}> $options */
        $options = [
            'url' => ['env' => 'URL'],
            'host' => ['env' => 'HOST'],
            'port' => ['env' => 'PORT', 'rules' => static fn ($value): bool => ! empty($value) && \is_int($value)],
            'database' => ['env' => ['DB', 'DATABASE']],
            'username' => ['env' => ['USER', 'USERNAME']],
            'password' => ['env' => 'PASSWORD', 'rules' => static fn ($value): bool => \is_null($value) || \is_string($value)],
            'collation' => ['env' => 'COLLATION', 'rules' => static fn ($value): bool => \is_null($value) || \is_string($value)],
        ];

        $config->set(
            (new Collection($options))
                ->when($driver === 'pgsql', static fn (Collection $options): Collection => $options->put('schema', ['env' => 'SCHEMA']))
                ->mapWithKeys(static function (array $options, string $key) use ($driver, $keyword, $config): array {
                    $name = "database.connections.{$driver}.{$key}";

                    /** @var mixed $configuration */
                    $configuration = (new Collection(Arr::wrap($options['env'])))
                        ->transform(static fn (string $value): mixed => Env::get("{$keyword}_{$value}"))
                        ->first(
                            $options['rules'] ?? static fn ($value): bool => ! empty($value) && \is_string($value)
                        ) ?? $config->get($name);

                    return [$name => $configuration];
                })->all(),
        );
    }
}
