<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use PDO;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_mysql')]
class DatabaseEmulatePreparesMariaDbConnectionTest extends DatabaseMariaDbConnectionTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.mariadb.options', [
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    }
}
