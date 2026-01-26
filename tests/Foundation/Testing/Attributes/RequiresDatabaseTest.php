<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Attributes;

use Hypervel\Foundation\Testing\Attributes\RequiresDatabase;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class RequiresDatabaseTest extends TestCase
{
    public function testSkipsWhenDriverDoesNotMatch(): void
    {
        $attribute = new RequiresDatabase('pgsql');

        $skipped = false;
        $skipMessage = null;

        $action = function (string $method, array $params) use (&$skipped, &$skipMessage): void {
            if ($method === 'markTestSkipped') {
                $skipped = true;
                $skipMessage = $params[0] ?? '';
            }
        };

        $attribute->handle($this->app, $action);

        // Default connection is sqlite, so pgsql requirement should skip
        $this->assertTrue($skipped);
        $this->assertStringContainsString('pgsql', $skipMessage);
    }

    public function testDoesNotSkipWhenDriverMatches(): void
    {
        $attribute = new RequiresDatabase('sqlite');

        $skipped = false;

        $action = function (string $method, array $params) use (&$skipped): void {
            if ($method === 'markTestSkipped') {
                $skipped = true;
            }
        };

        $attribute->handle($this->app, $action);

        // Default connection is sqlite, so it should not skip
        $this->assertFalse($skipped);
    }

    public function testAcceptsArrayOfDrivers(): void
    {
        $attribute = new RequiresDatabase(['sqlite', 'mysql'], connection: 'default');

        $skipped = false;

        $action = function (string $method, array $params) use (&$skipped): void {
            if ($method === 'markTestSkipped') {
                $skipped = true;
            }
        };

        $attribute->handle($this->app, $action);

        // sqlite is in the array, should not skip
        $this->assertFalse($skipped);
    }

    public function testSkipsWhenDriverNotInArray(): void
    {
        $attribute = new RequiresDatabase(['pgsql', 'mysql'], connection: 'default');

        $skipped = false;
        $skipMessage = null;

        $action = function (string $method, array $params) use (&$skipped, &$skipMessage): void {
            if ($method === 'markTestSkipped') {
                $skipped = true;
                $skipMessage = $params[0] ?? '';
            }
        };

        $attribute->handle($this->app, $action);

        // sqlite is not in [pgsql, mysql], should skip
        $this->assertTrue($skipped);
        $this->assertStringContainsString('pgsql/mysql', $skipMessage);
    }

    public function testThrowsWhenArrayWithDefaultTrue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to validate default connection when given an array of database drivers');

        new RequiresDatabase(['sqlite', 'pgsql'], default: true);
    }

    public function testDefaultIsTrueWhenNoConnectionSpecified(): void
    {
        $attribute = new RequiresDatabase('sqlite');

        $this->assertTrue($attribute->default);
    }

    public function testDefaultIsNullWhenConnectionSpecified(): void
    {
        $attribute = new RequiresDatabase('sqlite', connection: 'testing');

        $this->assertNull($attribute->default);
    }
}
