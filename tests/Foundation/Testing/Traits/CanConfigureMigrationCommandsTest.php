<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Traits;

use Hypervel\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Hypervel\Tests\TestCase;
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
        parent::setUp();

        $this->traitObject = new CanConfigureMigrationCommandsTestMockClass();
    }

    private function __reflectAndSetupAccessibleForProtectedTraitMethod($methodName)
    {
        return new ReflectionMethod(
            get_class($this->traitObject),
            $methodName
        );
    }

    public function testMigrateFreshUsingDefault()
    {
        $migrateFreshUsingReflection = $this->__reflectAndSetupAccessibleForProtectedTraitMethod('migrateFreshUsing');

        $expected = [
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => false,
        ];

        $this->assertEquals($expected, $migrateFreshUsingReflection->invoke($this->traitObject));
    }

    public function testMigrateFreshUsingWithPropertySets()
    {
        $migrateFreshUsingReflection = $this->__reflectAndSetupAccessibleForProtectedTraitMethod('migrateFreshUsing');

        $expected = [
            '--drop-views' => true,
            '--drop-types' => false,
            '--seed' => false,
        ];

        $this->traitObject->dropViews = true;

        $this->assertEquals($expected, $migrateFreshUsingReflection->invoke($this->traitObject));

        $expected = [
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => false,
        ];

        $this->traitObject->dropViews = false;

        $this->assertEquals($expected, $migrateFreshUsingReflection->invoke($this->traitObject));
    }
}

class CanConfigureMigrationCommandsTestMockClass
{
    use CanConfigureMigrationCommands;

    public bool $dropViews = false;
}
