<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('database.default', 'testing')]
class RequiresDatabaseTest extends TestCase
{
    #[Test]
    public function itCanValidateMatchingDatabase(): void
    {
        $actions = [];
        foreach (['sqlite', ['pgsql', 'sqlite']] as $driver) {
            (new RequiresDatabase($driver))->handle($this->app, function (string $method) use (&$actions): void {
                $actions[] = $method;
            });
        }

        $this->assertSame([], $actions);
    }

    #[Test]
    public function itCanInvalidateUnmatchedDatabase(): void
    {
        $actions = [];
        foreach (['mysql', ['mysql', 'mariadb']] as $driver) {
            (new RequiresDatabase($driver))->handle($this->app, function (string $method, array $parameters) use (&$actions): void {
                $actions[] = [$method, $parameters];
            });
        }

        $this->assertSame([
            ['markTestSkipped', ['Requires mysql to be configured for "testing" database connection']],
            ['markTestSkipped', ['Requires [mysql/mariadb] to be configured for "testing" database connection']],
        ], $actions);
    }

    #[Test]
    public function itCanInvalidateUnmatchedDatabaseVersion(): void
    {
        $actions = [];
        $stub = new RequiresDatabase('sqlite', '<2.0.0');

        $stub->handle($this->app, function (string $method, array $parameters) use (&$actions): void {
            $actions[] = [$method, $parameters];
        });

        $this->assertSame([['markTestSkipped', ['Requires sqlite:<2.0.0 to be configured for "testing" database connection']]], $actions);
    }
}
