<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Drivers;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Pennant\Contracts\CanListStoredFeatures;
use Hypervel\Pennant\Contracts\CanSetManyFeaturesForScopes;
use Hypervel\Pennant\Contracts\Driver;
use Hypervel\Pennant\Contracts\UsesDatabaseConnection;
use Hypervel\Pennant\Events\UnknownFeatureResolved;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\FeaturePartition;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use InvalidArgumentException;
use Pdo\Mysql;
use RuntimeException;
use stdClass;

class DatabaseDriver implements CanListStoredFeatures, CanSetManyFeaturesForScopes, Driver, UsesDatabaseConnection
{
    /**
     * The database manager.
     */
    protected DatabaseManager $db;

    /**
     * The store's connection name, or null for the default connection.
     */
    protected ?string $connection;

    /**
     * The store's table.
     */
    protected string $table;

    /**
     * The resolver of the store's current partition.
     *
     * @var null|(Closure(): FeaturePartition)
     */
    protected ?Closure $partitionResolver;

    /**
     * The shared cache of stored values.
     */
    protected ?StoredValueCache $cache;

    /**
     * The feature state resolvers.
     *
     * @var array<string, (callable(mixed): mixed)>
     */
    protected array $featureStateResolvers;

    /**
     * The sentinel value for unknown features.
     */
    protected stdClass $unknownFeatureValue;

    /**
     * The name of the "created at" column.
     */
    public const string CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     */
    public const string UPDATED_AT = 'updated_at';

    /**
     * The maximum number of feature groups in one read query.
     *
     * Half of SQLite's default expression depth limit of 1000.
     */
    public const int QUERY_GROUP_LIMIT = 500;

    /**
     * The maximum number of bindings in one read query.
     */
    public const int QUERY_BINDING_LIMIT = 10_000;

    /**
     * The maximum number of rows in one write statement.
     */
    public const int WRITE_ROW_LIMIT = 1000;

    /**
     * Create a new driver instance.
     *
     * @param null|(Closure(): FeaturePartition) $partitionResolver
     * @param array<string, (callable(mixed $scope): mixed)> $featureStateResolvers
     */
    public function __construct(
        DatabaseManager $db,
        ?string $connection,
        string $table,
        ?Closure $partitionResolver,
        ?StoredValueCache $cache,
        array $featureStateResolvers,
    ) {
        $this->db = $db;
        $this->connection = $connection;
        $this->table = $table;
        $this->partitionResolver = $partitionResolver;
        $this->cache = $cache;
        $this->featureStateResolvers = $featureStateResolvers;

        $this->unknownFeatureValue = new stdClass;
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * Boot or tests only. The definition persists on the store for the worker
     * lifetime and applies to every subsequent request.
     *
     * @param (callable(mixed $scope): mixed) $resolver
     */
    public function define(string $feature, callable $resolver): void
    {
        $this->featureStateResolvers[$feature] = $resolver;
    }

    /**
     * Retrieve the names of all defined features.
     *
     * @return array<string>
     */
    public function defined(): array
    {
        return array_keys($this->featureStateResolvers);
    }

    /**
     * Retrieve the names of all stored features.
     *
     * @return array<string>
     */
    public function stored(): array
    {
        return $this->newPartitionQuery($this->partition())
            ->select('name')
            ->distinct()
            ->get()
            ->pluck('name')
            ->all();
    }

    /**
     * Get multiple feature flag values.
     *
     * @param array<string, array<int, mixed>> $features
     * @return array<string, array<int, mixed>>
     */
    public function getAll(array $features): array
    {
        $pairs = [];
        $scopeKeys = [];

        foreach ($features as $feature => $scopes) {
            foreach ($scopes as $index => $scope) {
                $scopeKey = Feature::serializeScope($scope);

                $scopeKeys[$feature][$index] = $scopeKey;
                $pairs[$feature][$scopeKey] = $scope;
            }
        }

        $values = $pairs === [] ? [] : $this->retrieveValues($pairs);
        $results = [];

        foreach ($features as $feature => $scopes) {
            $results[$feature] = [];

            foreach ($scopeKeys[$feature] ?? [] as $index => $scopeKey) {
                $results[$feature][$index] = $values[$feature][$scopeKey];
            }
        }

        return $results;
    }

    /**
     * Retrieve a feature flag's value.
     */
    public function get(string $feature, mixed $scope): mixed
    {
        return $this->getAll([$feature => [$scope]])[$feature][0];
    }

    /**
     * Retrieve the values of the given features and scopes, storing initial values for any that are missing.
     *
     * @param array<string, array<array-key, mixed>> $pairs scopes keyed by their serialized identifiers
     * @return array<string, array<array-key, mixed>>
     */
    protected function retrieveValues(array $pairs): array
    {
        $connection = $this->connectionName();
        $partition = $this->partition();
        $values = [];
        $tokens = null;

        // Inside a transaction the database may hold uncommitted rows the cache must not see.
        if ($this->cache?->storesValues() && ! $this->db->hasApplicableTransaction($connection)) {
            [$values, $tokens] = $this->cache->read($connection, $partition, $pairs);

            foreach ($values as $feature => $scopes) {
                $pairs[$feature] = array_diff_key($pairs[$feature], $scopes);

                if ($pairs[$feature] === []) {
                    unset($pairs[$feature]);
                }
            }
        }

        if ($pairs === []) {
            return $values;
        }

        $stored = [];

        // A replica may lag behind the write that rotated the cache tokens, so values published under them are read from the primary.
        foreach ($this->readStoredValues($pairs, $partition, useWritePdo: $tokens !== null) as $feature => $scopes) {
            foreach ($scopes as $scopeKey => $value) {
                $values[$feature][$scopeKey] = json_decode($value, flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
                $stored[$feature][$scopeKey] = $value;

                unset($pairs[$feature][$scopeKey]);
            }

            if ($pairs[$feature] === []) {
                unset($pairs[$feature]);
            }
        }

        if ($pairs !== []) {
            [$inserted, $insertedValues] = $this->insertMany($partition, $pairs);

            foreach ($inserted as $feature => $scopes) {
                foreach ($scopes as $scopeKey => $value) {
                    $values[$feature][$scopeKey] = $value;
                }
            }

            foreach ($insertedValues as $feature => $scopes) {
                foreach ($scopes as $scopeKey => $value) {
                    $stored[$feature][$scopeKey] = $value;
                }
            }
        }

        if ($tokens !== null && $stored !== []) {
            $this->cache->publish($connection, $partition, $tokens, $stored);
        }

        return $values;
    }

    /**
     * Read the stored JSON values of the given features and scopes.
     *
     * Features that share the same scopes are combined into one predicate, and the
     * predicates are packed into as few statements as the group and binding limits allow.
     *
     * @param array<string, array<array-key, mixed>> $pairs scopes keyed by their serialized identifiers
     * @return array<string, array<array-key, string>>
     */
    protected function readStoredValues(array $pairs, ?FeaturePartition $partition, bool $useWritePdo = false, bool $lock = false): array
    {
        $bindingLimit = self::QUERY_BINDING_LIMIT - ($partition === null ? 0 : 1);
        $groups = [];

        foreach ($pairs as $feature => $scopes) {
            $scopeKeys = array_map(strval(...), array_keys($scopes));

            sort($scopeKeys, SORT_STRING);

            $group = implode('', array_map(static fn (string $scope): string => strlen($scope) . ':' . $scope, $scopeKeys));

            $groups[$group] ??= ['features' => [], 'scopes' => $scopeKeys];
            $groups[$group]['features'][] = (string) $feature;
        }

        $predicates = [];

        foreach ($groups as ['features' => $features, 'scopes' => $scopes]) {
            if (count($features) + count($scopes) <= $bindingLimit) {
                $predicates[] = [$features, $scopes];

                continue;
            }

            foreach (array_chunk($features, intdiv($bindingLimit, 2)) as $featureChunk) {
                foreach (array_chunk($scopes, $bindingLimit - count($featureChunk)) as $scopeChunk) {
                    $predicates[] = [$featureChunk, $scopeChunk];
                }
            }
        }

        $statements = [];
        $statement = [];
        $bindings = 0;

        foreach ($predicates as $predicate) {
            $size = count($predicate[0]) + count($predicate[1]);

            if ($statement !== [] && (count($statement) === self::QUERY_GROUP_LIMIT || $bindings + $size > $bindingLimit)) {
                $statements[] = $statement;
                $statement = [];
                $bindings = 0;
            }

            $statement[] = $predicate;
            $bindings += $size;
        }

        if ($statement !== []) {
            $statements[] = $statement;
        }

        $values = [];

        foreach ($statements as $statement) {
            $query = $this->newPartitionQuery($partition)
                ->select(['name', 'scope', 'value'])
                ->where(function (Builder $query) use ($statement): void {
                    foreach ($statement as [$features, $scopes]) {
                        $query->orWhere(fn (Builder $query) => $query->whereIn('name', $features)->whereIn('scope', $scopes));
                    }
                });

            if ($useWritePdo) {
                $query->useWritePdo();
            }

            if ($lock) {
                $query->sharedLock();
            }

            foreach ($query->get() as $record) {
                $values[$record->name][$record->scope] = $record->value;
            }
        }

        return $values;
    }

    /**
     * Determine the initial value for a given feature and scope.
     */
    protected function resolveValue(string $feature, mixed $scope): mixed
    {
        if (! array_key_exists($feature, $this->featureStateResolvers)) {
            if (Event::hasListeners(UnknownFeatureResolved::class)) {
                Event::dispatch(new UnknownFeatureResolved($feature, $scope));
            }

            return $this->unknownFeatureValue;
        }

        return $this->featureStateResolvers[$feature]($scope);
    }

    /**
     * Set a feature flag's value.
     */
    public function set(string $feature, mixed $scope, mixed $value): void
    {
        $partition = $this->partition();
        $scope = Feature::serializeScope($scope);
        $now = CarbonImmutable::now();

        $this->newQuery()->upsert(
            $this->row($partition, $feature, $scope, json_encode($value, flags: JSON_THROW_ON_ERROR), $now),
            uniqueBy: $this->uniqueBy($partition),
            update: ['value', static::UPDATED_AT],
        );

        $this->invalidate($partition, [$scope]);
    }

    /**
     * Set multiple feature flag values.
     *
     * @param list<array{ feature: string, scope: mixed, value: mixed }> $features
     */
    public function setAll(array $features): void
    {
        $partition = $this->partition();
        $now = CarbonImmutable::now();
        $rows = [];
        $scopes = [];

        foreach ($features as $feature) {
            $scope = Feature::serializeScope($feature['scope']);

            // Like sequential writes, the last value for a repeated feature and scope wins.
            // PostgreSQL also rejects an upsert that updates the same row twice.
            $rows[strlen($feature['feature']) . ':' . $feature['feature'] . $scope] = $this->row(
                $partition,
                $feature['feature'],
                $scope,
                json_encode($feature['value'], flags: JSON_THROW_ON_ERROR),
                $now,
            );

            $scopes[$scope] = $scope;
        }

        $chunks = array_chunk($rows, self::WRITE_ROW_LIMIT);

        $write = function () use ($chunks, $partition): void {
            foreach ($chunks as $chunk) {
                $this->newQuery()->upsert($chunk, uniqueBy: $this->uniqueBy($partition), update: ['value', static::UPDATED_AT]);
            }
        };

        count($chunks) > 1 ? $this->connection()->transaction($write) : $write();

        $this->invalidate($partition, array_values($scopes));
    }

    /**
     * Set a feature flag's value for all scopes.
     */
    public function setForAllScopes(string $feature, mixed $value): void
    {
        $partition = $this->partition();

        $this->newPartitionQuery($partition)
            ->where('name', $feature)
            ->update([
                'value' => json_encode($value, flags: JSON_THROW_ON_ERROR),
                static::UPDATED_AT => CarbonImmutable::now(),
            ]);

        $this->invalidate($partition);
    }

    /**
     * Insert initial values for the given features and scopes, returning the values that are stored.
     *
     * When every candidate is known to be inserted, the resolved values are returned as they are.
     * Otherwise the rows are read back and their stored values returned, since a matching row may
     * belong to a concurrent insert. Rows that vanish before they can be read back, such as through
     * a concurrent purge, are resolved and inserted again, at most twice.
     *
     * @param array<string, array<array-key, mixed>> $pairs scopes keyed by their serialized identifiers
     * @return array{array<string, array<array-key, mixed>>, array<string, array<array-key, string>>} the values, and the stored JSON of those that are stored
     *
     * @throws RuntimeException when rows keep vanishing
     */
    protected function insertMany(?FeaturePartition $partition, array $pairs): array
    {
        $values = [];
        $stored = [];

        for ($attempt = 0; $pairs !== []; ++$attempt) {
            if ($attempt > 2) {
                throw new RuntimeException('Unable to insert feature values into the database.');
            }

            $now = CarbonImmutable::now();
            $candidates = [];
            $rows = [];

            foreach ($pairs as $feature => $scopes) {
                $feature = (string) $feature;

                foreach ($scopes as $scopeKey => $scope) {
                    $value = $this->resolveValue($feature, $scope);

                    if ($value === $this->unknownFeatureValue) {
                        $values[$feature][$scopeKey] = false;

                        continue;
                    }

                    $json = json_encode($value, flags: JSON_THROW_ON_ERROR);

                    $candidates[$feature][$scopeKey] = [$scope, $value, $json];
                    $rows[] = $this->row($partition, $feature, (string) $scopeKey, $json, $now);
                }
            }

            if ($rows === []) {
                break;
            }

            if ($this->insertCandidates($partition, $rows)) {
                foreach ($candidates as $feature => $scopes) {
                    foreach ($scopes as $scopeKey => [, $value, $json]) {
                        $values[$feature][$scopeKey] = $value;
                        $stored[$feature][$scopeKey] = $json;
                    }
                }

                break;
            }

            // A MySQL REPEATABLE READ snapshot hides the competing row from a plain read.
            $winners = $this->readStoredValues(
                $candidates,
                $partition,
                useWritePdo: true,
                lock: in_array($this->connection()->getDriverName(), ['mysql', 'mariadb'], true),
            );

            $pairs = [];

            foreach ($candidates as $feature => $scopes) {
                foreach ($scopes as $scopeKey => [$scope]) {
                    if (! isset($winners[$feature][$scopeKey])) {
                        $pairs[$feature][$scopeKey] = $scope;

                        continue;
                    }

                    $values[$feature][$scopeKey] = json_decode($winners[$feature][$scopeKey], flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
                    $stored[$feature][$scopeKey] = $winners[$feature][$scopeKey];
                }
            }
        }

        return [$values, $stored];
    }

    /**
     * Insert the candidate rows, skipping those that already exist, and determine whether every candidate was inserted.
     *
     * @param list<array<string, mixed>> $rows
     */
    protected function insertCandidates(?FeaturePartition $partition, array $rows): bool
    {
        $connection = $this->connection();
        $driver = $connection->getDriverName();
        $inserted = 0;

        foreach (array_chunk($rows, self::WRITE_ROW_LIMIT) as $chunk) {
            $query = $this->newQuery();

            // MySQL's INSERT IGNORE would also hide truncation and other data errors, so a table-qualified
            // no-op upsert skips duplicates instead (an unqualified column is ambiguous on PostgreSQL).
            $inserted += in_array($driver, ['mysql', 'mariadb'], true)
                ? $query->upsert($chunk, $this->uniqueBy($partition), ['name' => new Expression($query->getGrammar()->wrap("{$this->table}.name"))])
                : $query->insertOrIgnore($chunk);
        }

        // With CLIENT_FOUND_ROWS, MySQL counts duplicate rows as affected. Built-in connectors don't
        // enable it unless the connection options do; a custom connector may set it in its defaults.
        if (in_array($driver, ['mysql', 'mariadb'], true)
            && (Container::getInstance()->bound("db.connector.{$driver}") || ! empty($connection->getConfig('options')[Mysql::ATTR_FOUND_ROWS] ?? false))) {
            return false;
        }

        return $inserted === count($rows);
    }

    /**
     * Get a row for the given feature value.
     *
     * @return array<string, mixed>
     */
    protected function row(?FeaturePartition $partition, string $feature, string $scope, string $value, CarbonImmutable $now): array
    {
        $row = [
            'name' => $feature,
            'scope' => $scope,
            'value' => $value,
            static::CREATED_AT => $now,
            static::UPDATED_AT => $now,
        ];

        if ($partition !== null) {
            $row[$partition->column] = $partition->value;
        }

        return $row;
    }

    /**
     * Get the columns that identify a stored value.
     *
     * @return list<string>
     */
    protected function uniqueBy(?FeaturePartition $partition): array
    {
        return $partition === null ? ['name', 'scope'] : [$partition->column, 'name', 'scope'];
    }

    /**
     * Delete a feature flag's value.
     */
    public function delete(string $feature, mixed $scope): void
    {
        $partition = $this->partition();
        $scope = Feature::serializeScope($scope);

        $this->newPartitionQuery($partition)
            ->where('name', $feature)
            ->where('scope', $scope)
            ->delete();

        $this->invalidate($partition, [$scope]);
    }

    /**
     * Purge the given feature from storage.
     */
    public function purge(?array $features): void
    {
        $partition = $this->partition();

        if ($features === null) {
            $this->newPartitionQuery($partition)->delete();
        } else {
            $this->newPartitionQuery($partition)
                ->whereIn('name', $features)
                ->delete();
        }

        $this->invalidate($partition);
    }

    /**
     * Rotate the shared cache tokens of the given scopes, or of the whole partition, once the write commits.
     *
     * @param null|list<string> $scopes
     */
    protected function invalidate(?FeaturePartition $partition, ?array $scopes = null): void
    {
        if ($this->cache === null) {
            return;
        }

        $connection = $this->connectionName();

        $this->connection()->afterCommitOrNow(fn () => $scopes === null
            ? $this->cache->rotatePartition($connection, $partition)
            : $this->cache->rotateScopes($connection, $partition, $scopes));
    }

    /**
     * Get the name of the store's current connection.
     *
     * @throws InvalidArgumentException when the connection is a read connection
     */
    public function connectionName(): string
    {
        $connection = $this->connection ?? $this->db->getDefaultConnection();

        // First resolution writes, and every statement on a read connection goes to a replica.
        if (str_ends_with($connection, '::' . ConnectionName::READ)) {
            throw new InvalidArgumentException("Pennant can't use the read connection [{$connection}]. Use its base or write connection instead.");
        }

        return $connection;
    }

    /**
     * Get the store's current partition.
     */
    protected function partition(): ?FeaturePartition
    {
        return $this->partitionResolver === null ? null : ($this->partitionResolver)();
    }

    /**
     * Create a new table query.
     */
    protected function newQuery(): Builder
    {
        return $this->connection()->table($this->table);
    }

    /**
     * Create a new table query limited to the given partition.
     */
    protected function newPartitionQuery(?FeaturePartition $partition): Builder
    {
        $query = $this->newQuery();

        if ($partition !== null) {
            $query->where($partition->column, $partition->value);
        }

        return $query;
    }

    /**
     * The database connection.
     */
    protected function connection(): Connection
    {
        /** @var Connection */
        return $this->db->connection($this->connectionName());
    }
}
