<?php

declare(strict_types=1);

namespace Hypervel\Types\Database\Schema;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;

use function PHPStan\Testing\assertType;

function testIndexDefinitionsUseConcreteTypes(Blueprint $table): void
{
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->primary('id'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->unique('email'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->index('name'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->fullText('body'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->spatialIndex('location'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->vectorIndex('embedding'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->rawIndex('(lower(email))', 'users_email_lower_index'));
    assertType(
        'Hypervel\Database\Schema\IndexDefinition',
        $table->index('archived_at')->whereNotNull('archived_at'),
    );
}

function testSchemaCallbackAndReferenceTypes(Builder $schema): void
{
    assertType('int<0, max>|null', Builder::$defaultStringLength);
    assertType("'int'|'ulid'|'uuid'", Builder::$defaultMorphKeyType);
    assertType('Closure(int<0, max>): void', Builder::defaultStringLength(...));
    assertType('42', $schema->withoutForeignKeyConstraints(fn (): int => 42));
    assertType('array{string|null, string}', $schema->parseSchemaAndTable('users'));

    new Blueprint($schema->getConnection(), 'users', function ($table): void {
        assertType('Hypervel\Database\Schema\Blueprint', $table);

        $table->after('id', function ($table): void {
            assertType('Hypervel\Database\Schema\Blueprint', $table);
        });
    });
}

function testDdlLockTypes(Blueprint $table): void
{
    assertType(
        "Closure('default'|'exclusive'|'none'|'shared'): Hypervel\\Database\\Schema\\ColumnDefinition",
        $table->string('name')->lock(...),
    );
    assertType(
        "Closure('default'|'exclusive'|'none'|'shared'): Hypervel\\Database\\Schema\\IndexDefinition",
        $table->index('name')->lock(...),
    );
    assertType(
        "Closure('default'|'exclusive'|'none'|'shared'): Hypervel\\Database\\Schema\\ForeignKeyDefinition",
        $table->foreign('user_id')->lock(...),
    );
    assertType(
        'Closure(array<string>|string): Hypervel\Database\Schema\ForeignKeyDefinition',
        $table->foreign('user_id')->references(...),
    );
}
