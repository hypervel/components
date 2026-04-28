<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Workbench;

use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\workbench;
use function Hypervel\Testbench\workbench_path;

class HelpersTest extends TestCase
{
    #[Test]
    public function itCanResolveWorkbench()
    {
        $this->instance(ConfigContract::class, new Config([
            'workbench' => [
                'start' => '/workbench',
                'user' => 'crynobone@gmail.com',
                'guard' => 'web',
                'install' => false,
                'welcome' => false,
                'health' => false,
                'discovers' => [
                    'web' => true,
                ],
            ],
        ]));

        $this->assertSame([
            'start' => '/workbench',
            'user' => 'crynobone@gmail.com',
            'guard' => 'web',
            'install' => false,
            'auth' => false,
            'welcome' => false,
            'health' => false,
            'sync' => [],
            'build' => [],
            'assets' => [],
            'discovers' => [
                'config' => false,
                'factories' => false,
                'web' => true,
                'api' => false,
                'commands' => false,
                'components' => false,
                'views' => false,
            ],
        ], workbench());
    }

    #[Test]
    public function itCanResolveWorkbenchWithoutBound()
    {
        $this->assertSame([
            'start' => '/',
            'user' => null,
            'guard' => null,
            'install' => true,
            'auth' => false,
            'welcome' => null,
            'health' => null,
            'sync' => [],
            'build' => [],
            'assets' => [],
            'discovers' => [
                'config' => false,
                'factories' => false,
                'web' => false,
                'api' => false,
                'commands' => false,
                'components' => false,
                'views' => false,
            ],
        ], workbench());
    }

    #[Test]
    public function itCanResolveWorkbenchPath()
    {
        $expected = realpath(package_path('src/testbench/workbench/database/migrations/2013_07_26_182750_create_testbench_users_table.php'));

        $this->assertSame(
            realpath(package_path('src/testbench/workbench/database/migrations/2013_07_26_182750_create_testbench_users_table.php')),
            workbench_path(join_paths('database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2013_07_26_182750_create_testbench_users_table.php'))
        );

        $this->assertSame(
            $expected,
            workbench_path('database', 'migrations', '2013_07_26_182750_create_testbench_users_table.php')
        );

        $this->assertSame(
            $expected,
            workbench_path(['database', 'migrations', '2013_07_26_182750_create_testbench_users_table.php'])
        );
    }
}
