<?php

declare(strict_types=1);

namespace UnusableSchemaDump;

use Hypervel\Database\Eloquent\Model;
use UnitEnum;

class MySqlAccount extends Model
{
    protected UnitEnum|string|null $connection = 'mysql';

    protected ?string $table = 'accounts';
}

class MariaDbAccount extends Model
{
    protected UnitEnum|string|null $connection = 'mariadb';

    protected ?string $table = 'accounts';
}

class PostgresAccount extends Model
{
    protected UnitEnum|string|null $connection = 'pgsql';

    protected ?string $table = 'accounts';
}

class SqliteUser extends Model
{
    protected ?string $table = 'users';
}

function lookups(MySqlAccount $mySql, MariaDbAccount $mariaDb, ?PostgresAccount $postgres, SqliteUser $sqlite): void
{
    // Each dump is reported once, however many lookups consult it.
    echo $mySql->label;
    echo $mySql->id;

    // A declared property does not consult the schema.
    echo $mariaDb->exists ? 'yes' : 'no';

    echo $postgres?->label;

    // The default connection's facts come from usable migrations.
    echo $sqlite->name;
}
