<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use PDO;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_mysql')]
class DatabaseEmulatePreparesMariaDbConnectionTest extends DatabaseMariaDbConnectionTest
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.connections.mariadb.options', [
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    }
}
