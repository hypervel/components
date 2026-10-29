<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use ArrayObject;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Concerns\HasAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

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
        // Clear any JSON error left by an earlier test; the empty-string cast returns without decoding.
        json_decode('[]');

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testReplacingRawAttributesDiscardsCachedCastValues(bool $sync): void
    {
        $instance = new HasAttributesWithCachedObjects;
        $original = ['payload' => '{"days":30}', 'settings' => '{"days":30}'];
        $replacement = ['payload' => '{"days":15}', 'settings' => '{"days":15}'];
        $instance->setRawAttributes($original, true);

        $this->assertSame(30, $instance->getAttribute('payload')['days']);
        $this->assertSame(30, $instance->getAttribute('settings')['days']);

        $instance->setRawAttributes($replacement, $sync);

        $this->assertSame($replacement, $instance->getAttributes());
        $this->assertSame(15, $instance->getAttribute('payload')['days']);
        $this->assertSame(15, $instance->getAttribute('settings')['days']);
        $this->assertSame($sync ? $replacement : $original, $instance->getRawOriginal());
        $this->assertSame(! $sync, $instance->isDirty());
    }
}

class HasAttributesWithCachedObjects extends Model
{
    protected array $casts = ['payload' => AsArrayObject::class];

    /**
     * Get the cached settings object.
     */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: static fn (string $value): ArrayObject => new ArrayObject(json_decode($value, true, flags: JSON_THROW_ON_ERROR)),
            set: static fn (ArrayObject $value): string => json_encode($value->getArrayCopy(), JSON_THROW_ON_ERROR),
        );
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
