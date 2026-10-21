<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Seeder;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\DB;
use Hypervel\Testing\Constraints\CountInDatabase;
use Hypervel\Testing\Constraints\HasInDatabase;
use Hypervel\Testing\Constraints\NotSoftDeletedInDatabase;
use Hypervel\Testing\Constraints\SoftDeletedInDatabase;
use PHPUnit\Framework\Constraint\LogicalNot as ReverseConstraint;
use UnitEnum;

trait InteractsWithDatabase
{
    /**
     * Assert that a given where condition exists in the database.
     *
     * @param class-string<Model>|iterable<class-string<Model>|Model|string>|Model|string $table
     * @param array<string, mixed>|list<array<string, mixed>> $data
     */
    protected function assertDatabaseHas(iterable|Model|string $table, array $data = [], UnitEnum|string|null $connection = null): static
    {
        if (is_iterable($table)) {
            foreach ($table as $item) {
                $this->assertDatabaseHas($item, $data, $connection);
            }

            return $this;
        }

        if ($data !== [] && array_is_list($data) && array_all($data, fn ($row) => is_array($row))) {
            foreach ($data as $row) {
                $this->assertDatabaseHas($table, $row, $connection);
            }

            return $this;
        }

        if ($table instanceof Model) {
            $data = [
                $table->getKeyName() => $table->getKey(),
                ...$data,
            ];
        }

        $this->assertThat(
            $this->getTable($table),
            new HasInDatabase($this->getConnection($connection, $table), $data)
        );

        return $this;
    }

    /**
     * Assert that a given where condition does not exist in the database.
     *
     * @param class-string<Model>|iterable<class-string<Model>|Model|string>|Model|string $table
     * @param array<string, mixed>|list<array<string, mixed>> $data
     */
    protected function assertDatabaseMissing(iterable|Model|string $table, array $data = [], UnitEnum|string|null $connection = null): static
    {
        if (is_iterable($table)) {
            foreach ($table as $item) {
                $this->assertDatabaseMissing($item, $data, $connection);
            }

            return $this;
        }

        if ($data !== [] && array_is_list($data) && array_all($data, fn ($row) => is_array($row))) {
            foreach ($data as $row) {
                $this->assertDatabaseMissing($table, $row, $connection);
            }

            return $this;
        }

        if ($table instanceof Model) {
            $data = [
                $table->getKeyName() => $table->getKey(),
                ...$data,
            ];
        }

        $constraint = new ReverseConstraint(
            new HasInDatabase($this->getConnection($connection, $table), $data)
        );

        $this->assertThat($this->getTable($table), $constraint);

        return $this;
    }

    /**
     * Assert the count of table entries.
     *
     * @param class-string<Model>|Model|string $table
     */
    protected function assertDatabaseCount(Model|string $table, int $count, UnitEnum|string|null $connection = null): static
    {
        $this->assertThat(
            $this->getTable($table),
            new CountInDatabase($this->getConnection($connection, $table), $count)
        );

        return $this;
    }

    /**
     * Assert that the given table or tables has no entries.
     *
     * @param class-string<Model>|iterable<class-string<Model>|Model|string>|Model|string $table
     */
    protected function assertDatabaseEmpty(iterable|Model|string $table, UnitEnum|string|null $connection = null): static
    {
        if (is_iterable($table)) {
            foreach ($table as $item) {
                $this->assertDatabaseEmpty($item, $connection);
            }

            return $this;
        }

        $this->assertThat(
            $this->getTable($table),
            new CountInDatabase($this->getConnection($connection, $table), 0)
        );

        return $this;
    }

    /**
     * Assert the given record has been "soft deleted".
     *
     * @param class-string<Model>|iterable<class-string<Model>|Model|string>|Model|string $table
     * @param array<string, mixed>|list<array<string, mixed>> $data
     */
    protected function assertSoftDeleted(iterable|Model|string $table, array $data = [], UnitEnum|string|null $connection = null, ?string $deletedAtColumn = 'deleted_at'): static
    {
        if (is_iterable($table)) {
            foreach ($table as $item) {
                $this->assertSoftDeleted($item, $data, $connection, $deletedAtColumn);
            }

            return $this;
        }

        // Expand row lists before applying a model, so every row gets its key, connection and deleted-at column.
        if ($data !== [] && array_is_list($data) && array_all($data, fn ($row) => is_array($row))) {
            foreach ($data as $row) {
                $this->assertSoftDeleted($table, $row, $connection, $deletedAtColumn);
            }

            return $this;
        }

        if ($this->isSoftDeletableModel($table)) {
            return $this->assertSoftDeleted(
                $table->getTable(),
                array_merge($data, [$table->getKeyName() => $table->getKey()]),
                $table->getConnectionName(),
                $table->getDeletedAtColumn()
            );
        }

        $this->assertThat(
            $this->getTable($table),
            new SoftDeletedInDatabase(
                $this->getConnection($connection, $table),
                $data,
                $this->getDeletedAtColumn($table, $deletedAtColumn)
            )
        );

        return $this;
    }

    /**
     * Assert the given record has not been "soft deleted".
     *
     * @param class-string<Model>|iterable<class-string<Model>|Model|string>|Model|string $table
     * @param array<string, mixed>|list<array<string, mixed>> $data
     */
    protected function assertNotSoftDeleted(iterable|Model|string $table, array $data = [], UnitEnum|string|null $connection = null, ?string $deletedAtColumn = 'deleted_at'): static
    {
        if (is_iterable($table)) {
            foreach ($table as $item) {
                $this->assertNotSoftDeleted($item, $data, $connection, $deletedAtColumn);
            }

            return $this;
        }

        // Expand row lists before applying a model, so every row gets its key, connection and deleted-at column.
        if ($data !== [] && array_is_list($data) && array_all($data, fn ($row) => is_array($row))) {
            foreach ($data as $row) {
                $this->assertNotSoftDeleted($table, $row, $connection, $deletedAtColumn);
            }

            return $this;
        }

        if ($this->isSoftDeletableModel($table)) {
            return $this->assertNotSoftDeleted(
                $table->getTable(),
                array_merge($data, [$table->getKeyName() => $table->getKey()]),
                $table->getConnectionName(),
                $table->getDeletedAtColumn()
            );
        }

        $this->assertThat(
            $this->getTable($table),
            new NotSoftDeletedInDatabase(
                $this->getConnection($connection, $table),
                $data,
                $this->getDeletedAtColumn($table, $deletedAtColumn)
            )
        );

        return $this;
    }

    /**
     * Assert the given model exists in the database.
     *
     * @param class-string<Model>|iterable<class-string<Model>|Model|string>|Model|string $model
     */
    protected function assertModelExists(iterable|Model|string $model): static
    {
        return $this->assertDatabaseHas($model);
    }

    /**
     * Assert the given model does not exist in the database.
     *
     * @param class-string<Model>|iterable<class-string<Model>|Model|string>|Model|string $model
     */
    protected function assertModelMissing(iterable|Model|string $model): static
    {
        return $this->assertDatabaseMissing($model);
    }

    /**
     * Specify the number of database queries that should occur throughout the test.
     */
    public function expectsDatabaseQueryCount(int $expected, UnitEnum|string|null $connection = null): static
    {
        with($this->getConnection($connection), function ($connectionInstance) use ($expected, $connection) {
            $actual = 0;

            $connectionInstance->listen(function (QueryExecuted $event) use (&$actual, $connectionInstance, $connection) {
                if (is_null($connection) || $connectionInstance === $event->connection) {
                    ++$actual;
                }
            });

            $this->beforeApplicationDestroyed(function () use (&$actual, $expected, $connectionInstance) {
                $this->assertSame(
                    $expected,
                    $actual,
                    "Expected {$expected} database queries on the [{$connectionInstance->getName()}] connection. {$actual} occurred."
                );
            });
        });

        return $this;
    }

    /**
     * Determine if the argument is a soft deletable model.
     *
     * @phpstan-assert-if-true Model $model
     */
    protected function isSoftDeletableModel(mixed $model): bool
    {
        return $model instanceof Model && $model::isSoftDeletable();
    }

    /**
     * Cast a JSON string to a database compatible type.
     */
    public function castAsJson(array|object|string $value, UnitEnum|string|null $connection = null): Expression
    {
        if ($value instanceof Jsonable) {
            $value = $value->toJson();
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        $database = DB::connection($connection);

        $value = $database->escape($value);

        return $database->raw(
            $database->getQueryGrammar()->compileJsonValueCast($value)
        );
    }

    /**
     * Get the database connection.
     *
     * @param null|class-string<Model>|Model|string $table
     */
    protected function getConnection(UnitEnum|string|null $connection = null, Model|string|null $table = null): Connection
    {
        $database = $this->app->make('db');

        if ($connection === null || $connection === '') {
            $connection = $this->getTableConnection($table);
        }

        if ($connection === null || $connection === '') {
            $connection = $database->getDefaultConnection();
        }

        return $database->connection($connection);
    }

    /**
     * Get the table name from the given model or string.
     *
     * @param class-string<Model>|Model|string $table
     */
    protected function getTable(Model|string $table): string
    {
        if ($table instanceof Model) {
            return $table->getTable();
        }

        return $this->newModelFor($table)?->getTable() ?: $table;
    }

    /**
     * Get the table connection specified in the given model.
     *
     * @param null|class-string<Model>|Model|string $table
     */
    protected function getTableConnection(Model|string|null $table): ?string
    {
        if ($table instanceof Model) {
            return $table->getConnectionName();
        }

        return $this->newModelFor($table)?->getConnectionName();
    }

    /**
     * Get the table column name used for soft deletes.
     *
     * @param class-string<Model>|Model|string $table
     */
    protected function getDeletedAtColumn(Model|string $table, ?string $defaultColumnName = 'deleted_at'): ?string
    {
        return $this->newModelFor($table)?->getDeletedAtColumn() ?: $defaultColumnName;
    }

    /**
     * Get the model entity from the given model or string.
     *
     * @param null|class-string<Model>|Model|string $table
     */
    protected function newModelFor(Model|string|null $table): ?Model
    {
        return is_subclass_of($table, Model::class) ? (new $table) : null;
    }

    /**
     * Seed a given database connection.
     *
     * @param class-string<Seeder>|list<string>|string $class
     */
    public function seed(array|string $class = 'Database\Seeders\DatabaseSeeder'): static
    {
        foreach (Arr::wrap($class) as $class) {
            $this->artisan('db:seed', ['--class' => $class, '--no-interaction' => true]);
        }

        return $this;
    }
}
