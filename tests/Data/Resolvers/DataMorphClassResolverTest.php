<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Resolvers\DataMorphClassResolverTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class DataMorphClassResolverTest extends TestCase
{
    // Spatie's DataMorphClassResolver class is not included; creation selects the morphed class, which must be
    // a concrete subclass, so each case asserts the class from() creates.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testReturnsNullForNonAbstractClasses(): void
    {
        $this->assertSame(SimpleData::class, SimpleData::from(['string' => 'Hello'])::class);
    }

    public function testReturnsNullForNonPropertyMorphableClasses(): void
    {
        $class = new class extends Data {
            public string $name;
        };

        $this->assertSame($class::class, $class::from(['name' => 'Hello'])::class);
    }

    public function testCanResolveMorphClassBasedOnProperties(): void
    {
        $this->assertInstanceOf(
            TestMorphableUserData::class,
            TestAbstractMorphableData::from(['type' => 'user']),
        );
    }

    public function testCanResolveMorphClassWithMappedInputNames(): void
    {
        $this->assertInstanceOf(
            TestMorphablePostData::class,
            TestAbstractMorphableDataWithMappedInputName::from(['type_for_morph' => 'post']),
        );
    }

    public function testCanResolveMorphClassWithBackedEnumType(): void
    {
        $this->assertInstanceOf(
            TestMorphableFooData::class,
            TestAbstractMorphableDataWithBackedEnum::from(['type' => DummyBackedEnum::FOO]),
        );
    }

    public function testCanResolveMorphClassWithBackedEnumTypeUsingDefaultValue(): void
    {
        $this->assertInstanceOf(
            TestMorphableDefaultBooData::class,
            TestAbstractMorphableDataWithDefaultValue::from([]),
        );
    }

    public function testCanResolveMorphClassWithBackedEnumTypeIgnoringDefaultValue(): void
    {
        $this->assertInstanceOf(
            TestMorphableNullableFooData::class,
            TestAbstractMorphableDataWithNullableBackedEnum::from(['type' => DummyBackedEnum::FOO]),
        );
    }
}

abstract class TestAbstractMorphableData extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    public string $type;

    /**
     * Get the subclass for the given properties.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'user' ? TestMorphableUserData::class : null;
    }
}

class TestMorphableUserData extends TestAbstractMorphableData
{
}

abstract class TestAbstractMorphableDataWithMappedInputName extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    #[MapInputName('type_for_morph')]
    public string $type;

    /**
     * Get the subclass for the given properties.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'post' ? TestMorphablePostData::class : null;
    }
}

class TestMorphablePostData extends TestAbstractMorphableDataWithMappedInputName
{
}

abstract class TestAbstractMorphableDataWithBackedEnum extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    public DummyBackedEnum $type;

    /**
     * Get the subclass for the given properties.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === DummyBackedEnum::FOO ? TestMorphableFooData::class : null;
    }
}

class TestMorphableFooData extends TestAbstractMorphableDataWithBackedEnum
{
}

abstract class TestAbstractMorphableDataWithDefaultValue extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    public DummyBackedEnum $type = DummyBackedEnum::BOO;

    /**
     * Get the subclass for the given properties.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === DummyBackedEnum::BOO ? TestMorphableDefaultBooData::class : null;
    }
}

class TestMorphableDefaultBooData extends TestAbstractMorphableDataWithDefaultValue
{
}

abstract class TestAbstractMorphableDataWithNullableBackedEnum extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    public ?DummyBackedEnum $type = null;

    /**
     * Get the subclass for the given properties.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === DummyBackedEnum::FOO ? TestMorphableNullableFooData::class : null;
    }
}

class TestMorphableNullableFooData extends TestAbstractMorphableDataWithNullableBackedEnum
{
}
