<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Concerns\HasAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseConcernsHasAttributesTest extends TestCase
{
    public function testWithoutConstructor(): void
    {
        $instance = new HasAttributesWithoutConstructor;
        $attributes = $instance->getMutatedAttributes();
        $this->assertEquals(['some_attribute'], $attributes);
    }

    public function testWithConstructorArguments(): void
    {
        $instance = new HasAttributesWithConstructorArguments(null);
        $attributes = $instance->getMutatedAttributes();
        $this->assertEquals(['some_attribute'], $attributes);
    }

    public function testRelationsToArray(): void
    {
        $mock = m::mock(HasAttributesWithoutConstructor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->expects('getArrayableRelations')->andReturn([
                'arrayable_relation' => new Collection(['foo' => 'bar']),
                'invalid_relation' => 'invalid',
                'null_relation' => null,
            ])
            ->getMock();

        $this->assertEquals([
            'arrayable_relation' => ['foo' => 'bar'],
            'null_relation' => null,
        ], $mock->relationsToArray());
    }

    public function testCastingEmptyStringToArrayDoesNotError(): void
    {
        $instance = new HasAttributesWithArrayCast;
        $this->assertEquals(['foo' => null], $instance->attributesToArray());

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testUnsettingCachedAttribute(): void
    {
        $instance = new HasCacheableAttributeWithAccessor;
        $this->assertSame('foo', $instance->getAttribute('cacheableProperty'));
        $this->assertTrue($instance->cachedAttributeIsset('cacheableProperty'));

        unset($instance->cacheableProperty);

        $this->assertFalse($instance->cachedAttributeIsset('cacheableProperty'));
    }
}

class HasAttributesWithoutConstructor
{
    use HasAttributes;

    /**
     * Get the attribute definition.
     */
    public function someAttribute(): Attribute
    {
        return new Attribute(function (): void {
        });
    }
}

class HasAttributesWithConstructorArguments extends HasAttributesWithoutConstructor
{
    /**
     * Create a fixture with a required constructor argument.
     */
    public function __construct(mixed $someValue)
    {
    }
}

class HasAttributesWithArrayCast
{
    use HasAttributes;

    /**
     * Get the fixture attributes.
     */
    public function getArrayableAttributes(): array
    {
        return ['foo' => ''];
    }

    /**
     * Get the fixture casts.
     */
    public function getCasts(): array
    {
        return ['foo' => 'array'];
    }

    /**
     * Determine whether the fixture uses timestamps.
     */
    public function usesTimestamps(): bool
    {
        return false;
    }
}

/**
 * @property string $cacheableProperty
 */
class HasCacheableAttributeWithAccessor extends Model
{
    /**
     * Get the cacheable attribute definition.
     */
    public function cacheableProperty(): Attribute
    {
        return Attribute::make(
            get: fn (): string => 'foo'
        )->shouldCache();
    }

    /**
     * Determine whether an attribute value is cached.
     */
    public function cachedAttributeIsset(string $attribute): bool
    {
        return isset($this->attributeCastCache[$attribute]);
    }
}
