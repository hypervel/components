<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Resolvers\DataClassFromValidationPayloadResolverTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Validation\EnsurePropertyMorphable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\AbstractPropertyMorphableData;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class DataClassFromValidationPayloadResolverTest extends TestCase
{
    // Spatie's DataClassFromValidationPayloadResolver class is not included; Fill selects the morphed class
    // before rules compile, so each case asserts which class's rules the payload compiled.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testReturnsTheDataClassForNonMorphableClasses(): void
    {
        $this->assertSame(['string'], array_keys(SimpleData::getValidationRules(['string' => 'Hello'])));
    }

    public function testReturnsTheMorphedDataClassBasedOnThePayload(): void
    {
        $rules = AbstractPropertyMorphableData::getValidationRules(['variant' => 'a', 'a' => 'test', 'enum' => 'foo']);

        $this->assertSame(['a', 'enum', 'variant'], array_keys($rules));
    }

    public function testReturnsTheAbstractDataClassWhenMorphCannotBeResolved(): void
    {
        $rules = AbstractPropertyMorphableData::getValidationRules(['variant' => 'unknown']);

        $this->assertSame(['variant'], array_keys($rules));
        $this->assertInstanceOf(EnsurePropertyMorphable::class, $rules['variant'][array_key_last($rules['variant'])]);
    }

    public function testResolvesTheMorphedDataClassFromANestedPath(): void
    {
        $rules = NestedMorphableData::getValidationRules(['nested' => ['variant' => 'a', 'a' => 'test', 'enum' => 'foo']]);

        $this->assertSame(['nested', 'nested.a', 'nested.enum', 'nested.variant'], array_keys($rules));
    }
}

class NestedMorphableData extends Data
{
    public AbstractPropertyMorphableData $nested;
}
