<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\Laravel\SchemaBuilderSchemaNameTest as BaseSchemaBuilderSchemaNameTest;

/**
 * @internal
 * @coversNothing
 */
#[RequiresDatabase('sqlite')]
class SchemaBuilderSchemaNameTest extends BaseSchemaBuilderSchemaNameTest
{
}
