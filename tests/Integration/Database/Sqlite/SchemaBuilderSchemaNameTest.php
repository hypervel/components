<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\SchemaBuilderSchemaNameTest as BaseSchemaBuilderSchemaNameTest;

#[RequiresDatabase('sqlite')]
class SchemaBuilderSchemaNameTest extends BaseSchemaBuilderSchemaNameTest
{
}
