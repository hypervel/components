<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Traits;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class CanConfigureMigrationCommandsTest extends TestCase
{
    protected $traitObject;

    protected function setUp(): void
    {
        $this->traitObject = new CanConfigureMigrationCommandsTestMockClass();
    }

    private function __reflectAndSetupAccessibleForProtectedTraitMethod($methodName)
    {
        return new ReflectionMethod(
            get_class($this->traitObject),
            $methodName
        );
    }

    public function testMigrateFreshUsingDefault(): void
    {
        $migrateFreshUsingReflection = $this->__reflectAndSetupAccessibleForProtectedTraitMethod('migrateFreshUsing');

        $expected = [
            '--drop-views' => false,
            '--seed' => false,
            '--database' => 'default',
        ];

        $this->assertEquals($expected, $migrateFreshUsingReflection->invoke($this->traitObject));
    }

    public function testMigrateFreshUsingWithPropertySets(): void
    {
        $migrateFreshUsingReflection = $this->__reflectAndSetupAccessibleForProtectedTraitMethod('migrateFreshUsing');

        $expected = [
            '--drop-views' => true,
            '--seed' => false,
            '--database' => 'default',
        ];

        $this->traitObject->dropViews = true;

        $this->assertEquals($expected, $migrateFreshUsingReflection->invoke($this->traitObject));

        $expected = [
            '--drop-views' => false,
            '--seed' => false,
            '--database' => 'default',
        ];

        $this->traitObject->dropViews = false;

        $this->assertEquals($expected, $migrateFreshUsingReflection->invoke($this->traitObject));
    }

    protected function getConfig(array $config = []): Repository
    {
        return new Repository(array_merge([
            'database' => [
                'default' => 'default',
            ],
        ], $config));
    }
}

class CanConfigureMigrationCommandsTestMockClass
{
    use CanConfigureMigrationCommands;
    use HasMockedApplication;

    public bool $dropViews = false;

    public $app;

    public function __construct()
    {
        $this->app = $this->getApplication([
            ConfigContract::class => fn () => $this->getConfig(),
        ]);
    }

    protected function getConfig(array $config = []): Repository
    {
        return new Repository(array_merge([
            'database' => [
                'default' => 'default',
            ],
        ], $config));
    }
}
