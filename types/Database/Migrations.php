<?php

declare(strict_types=1);

namespace Hypervel\Types\Database\Migrations;

use Hypervel\Database\Connection;
use Hypervel\Database\Migrations\DatabaseMigrationRepository;
use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Database\Migrations\Migrator;

use function PHPStan\Testing\assertType;

function testMigrationRepositoryTypes(MigrationRepositoryInterface $repository, DatabaseMigrationRepository $database): void
{
    assertType('array<string>', $repository->getRan());
    assertType('array<string>', $database->getRan());
    assertType('array<string, int|numeric-string>', $repository->getMigrationBatches());
    assertType('array<string, int|numeric-string>', $database->getMigrationBatches());

    assertType('array<object{migration: string, batch: int|numeric-string}>', $repository->getMigrations(1));
    assertType('array<object{migration: string, batch: int|numeric-string}>', $database->getMigrations(1));
    assertType('array<object{migration: string, batch: int|numeric-string}>', $repository->getMigrationsByBatch(1));
    assertType('array<object{migration: string, batch: int|numeric-string}>', $database->getMigrationsByBatch(1));
    assertType('array<object{migration: string, batch: int|numeric-string}>', $repository->getLast());
    assertType('array<object{migration: string, batch: int|numeric-string}>', $database->getLast());

    $repository->delete((object) ['migration' => 'create_users_table']);
    $database->delete((object) ['migration' => 'create_users_table']);

    $repository->delete((object) ['migration' => 'create_users_table', 'batch' => '1']);
    $database->delete((object) ['migration' => 'create_users_table', 'batch' => '1']);
    $repository->delete((object) ['id' => 1, 'migration' => 'create_users_table', 'batch' => 1]);
    $database->delete((object) ['id' => 1, 'migration' => 'create_users_table', 'batch' => 1]);
}

function testMigrationCallbackTypes(MigrationCreator $creator, Connection $connection): void
{
    $creator->afterCreate(function ($table, $path): void {
        assertType('string|null', $table);
        assertType('string', $path);
    });

    Migrator::resolveConnectionsUsing(function ($resolver, $name) use ($connection): Connection {
        assertType('Hypervel\Database\ConnectionResolverInterface', $resolver);
        assertType('string|null', $name);

        return $connection;
    });
}
