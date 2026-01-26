<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Foundation\Testing\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

#[RequiresDatabase('sqlite')]
abstract class SqliteTestCase extends DatabaseTestCase
{
    //
}
