<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Traits;

use Hypervel\Foundation\Testing\Attributes\Seed;
use Hypervel\Foundation\Testing\Attributes\Seeder;
use Hypervel\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Hypervel\Tests\TestCase;
use ReflectionMethod;

class CanConfigureMigrationCommandsTest extends TestCase
{
    protected $traitObject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitObject = new CanConfigureMigrationCommandsTestMockClass;
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

    public function testMigrateFreshUsingWithSeedProperty(): void
    {
        $this->traitObject->seed = true;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => true,
        ], $this->invokeMigrationConfiguration($this->traitObject));
    }

    public function testMigrateFreshUsingWithSeederProperty(): void
    {
        $this->traitObject->seeder = TestSeeder::class;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seeder' => TestSeeder::class,
        ], $this->invokeMigrationConfiguration($this->traitObject));
    }

    public function testMigrateFreshUsingWithSeedAttribute(): void
    {
        $test = new SeedAttributeTestClass;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => true,
        ], $this->invokeMigrationConfiguration($test));
    }

    public function testMigrateFreshUsingWithParentSeedAttribute(): void
    {
        $test = new ChildSeedAttributeTestClass;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => true,
        ], $this->invokeMigrationConfiguration($test));
    }

    public function testMigrateFreshUsingWithSeederAttribute(): void
    {
        $test = new SeederAttributeTestClass;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seeder' => TestSeeder::class,
        ], $this->invokeMigrationConfiguration($test));
    }

    public function testMigrateFreshUsingWithParentSeederAttribute(): void
    {
        $test = new ChildSeederAttributeTestClass;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seeder' => TestSeeder::class,
        ], $this->invokeMigrationConfiguration($test));
    }

    public function testSeedAttributeTakesPrecedenceOverSeedProperty(): void
    {
        $test = new SeedAttributeWithFalseSeedPropertyTestClass;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => true,
        ], $this->invokeMigrationConfiguration($test));
    }

    public function testSeederAttributeTakesPrecedenceOverSeederProperty(): void
    {
        $test = new SeederAttributeWithSeederPropertyTestClass;

        $this->assertSame([
            '--drop-views' => false,
            '--drop-types' => false,
            '--seeder' => TestSeeder::class,
        ], $this->invokeMigrationConfiguration($test));
    }

    private function invokeMigrationConfiguration(object $test): array
    {
        return (new ReflectionMethod($test, 'migrateFreshUsing'))->invoke($test);
    }
}

class CanConfigureMigrationCommandsTestMockClass
{
    use CanConfigureMigrationCommands;

    public bool $dropViews = false;

    public bool $seed = false;

    public ?string $seeder = null;
}

#[Seed]
class SeedAttributeTestClass
{
    use CanConfigureMigrationCommands;
}

class ChildSeedAttributeTestClass extends SeedAttributeTestClass
{
}

#[Seeder(TestSeeder::class)]
class SeederAttributeTestClass
{
    use CanConfigureMigrationCommands;
}

class ChildSeederAttributeTestClass extends SeederAttributeTestClass
{
}

#[Seed]
class SeedAttributeWithFalseSeedPropertyTestClass
{
    use CanConfigureMigrationCommands;

    public bool $seed = false;
}

#[Seeder(TestSeeder::class)]
class SeederAttributeWithSeederPropertyTestClass
{
    use CanConfigureMigrationCommands;

    public string $seeder = OtherTestSeeder::class;
}

class TestSeeder
{
}

class OtherTestSeeder
{
}
