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
    assertType('array<string, int>', $repository->getMigrationBatches());
    assertType('array<string, int>', $database->getMigrationBatches());

    assertType('array<object{id: int, migration: string, batch: int}>', $repository->getMigrations(1));
    assertType('array<object{id: int, migration: string, batch: int}>', $database->getMigrations(1));
    assertType('array<object{id: int, migration: string, batch: int}>', $repository->getMigrationsByBatch(1));
    assertType('array<object{id: int, migration: string, batch: int}>', $database->getMigrationsByBatch(1));
    assertType('array<object{id: int, migration: string, batch: int}>', $repository->getLast());
    assertType('array<object{id: int, migration: string, batch: int}>', $database->getLast());

    $repository->delete((object) ['migration' => 'create_users_table']);
    $database->delete((object) ['migration' => 'create_users_table']);
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
