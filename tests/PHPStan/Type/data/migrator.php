<?php

namespace MigratorTypes;

use Hypervel\Database\Migrations\Migrator;
use function PHPStan\Testing\assertType;

function connection(Migrator $migrator): void
{
    assertType('42', $migrator->usingConnection(null, static fn () => 42));
}
