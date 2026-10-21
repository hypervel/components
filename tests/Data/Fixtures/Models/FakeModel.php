<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Exception;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Tests\Data\Fixtures\Factories\FakeModelFactory;

class FakeModel extends Model
{
    use HasFactory;

    protected array $guarded = [];

    protected array $casts = [
        'date' => 'immutable_datetime',
    ];

    /**
     * Get the fake nested models.
     */
    public function fakeNestedModels(): HasMany
    {
        return $this->hasMany(FakeNestedModel::class);
    }

    /**
     * Get the fake nested models through a snake-cased relation name.
     */
    public function fake_nested_models_snake_cased(): HasMany
    {
        return $this->hasMany(FakeNestedModel::class);
    }

    /**
     * Get the accessor attribute.
     */
    public function accessor(): Attribute
    {
        return Attribute::get(fn (): string => "accessor_{$this->string}");
    }

    /**
     * Get the old-style accessor attribute.
     */
    public function getOldAccessorAttribute(): string
    {
        return "old_accessor_{$this->string}";
    }

    /**
     * Get an old-style accessor that must not be evaluated.
     */
    public function getPerformanceHeavyAttribute(): never
    {
        throw new Exception('This attribute should not be called');
    }

    /**
     * Get an accessor that must not be evaluated.
     */
    public function performanceHeavyAccessor(): Attribute
    {
        return Attribute::get(fn (): never => throw new Exception('This accessor should not be called'));
    }

    /**
     * Get an accessor that reads a relation.
     */
    public function getAccessorUsingRelationAttribute(): string
    {
        return $this->fakeNestedModels->first()->string;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FakeModelFactory
    {
        return FakeModelFactory::new();
    }

    /**
     * Get the string attribute.
     *
     * Method to confuse isRelation, see https://github.com/spatie/laravel-data/issues/978
     */
    public function string(): string
    {
        return $this->string;
    }

    /**
     * Get an attribute from the model.
     *
     * Method to check for compatibility with astrotomic/translatable
     */
    public function getAttribute(string $key): mixed
    {
        if ($key === 'translated') {
            return 'translated_string';
        }

        return parent::getAttribute($key);
    }
}
