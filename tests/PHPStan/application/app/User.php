<?php

namespace App;

use App\Casts\BackedEnumeration;
use App\Casts\BasicEnumeration;
use App\Casts\Favorites;
use App\Casts\Hash;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsEnumArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEnumCollection;
use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\HasOneThrough;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\Notifiable;
use Hypervel\Support\Str;

use function get_class;

/**
 * @property string $propertyDefinedOnlyInAnnotation
 *
 * @method Builder<static> scopeSomeScope(Builder $builder)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected array $fillable = [
        'name', 'email', 'password',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'meta' => 'array',
        'blocked' => 'boolean',
        'email_verified_at' => 'date',
        'allowed_ips' => 'encrypted:array',
        'floatButRoundedDecimalString' => 'decimal:1',
        'options' => AsArrayObject::class,
        'properties' => AsCollection::class,
        'enum_collection' => AsEnumCollection::class.':'.BackedEnumeration::class,
        'enum_array_object' => AsEnumArrayObject::class.':'.BasicEnumeration::class,
        'castable_with_argument' => AsStringable::class.':argument',
        'favorites' => Favorites::class,
        'secret' => Hash::class.':sha256',
        'basic_enum' => BasicEnumeration::class,
        'backed_enum' => BackedEnumeration::class,

        'int' => 'int',
        'integer' => 'integer',
        'real' => 'real',
        'float' => 'float',
        'double' => 'double',
        'decimal' => 'decimal',
        'nullable_decimal' => 'decimal:2',
        'string' => 'string',
        'bool' => 'bool',
        'boolean' => 'boolean',
        'object' => 'object',
        'array' => 'array',
        'json' => 'json',
        'collection' => 'collection',
        'nullable_collection' => 'collection',
        'date' => 'date',
        'datetime' => 'datetime',
        'immutable_date' => 'immutable_date',
        'immutable_datetime' => 'immutable_datetime',
        'timestamp' => 'timestamp',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected array $hidden = [
        'password', 'remember_token',
    ];

    public function id(): int
    {
        return $this->id;
    }

    public function getAllCapsName(): string
    {
        return Str::upper($this->name);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWhereActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    /** @phpstan-return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withTrashed();
    }

    /** @phpstan-return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts_snake(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accountsCamel(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @phpstan-return HasMany<ModelWithNonGenericCollection, $this> */
    public function modelsWithNonGenericCollection(): HasMany
    {
        return $this->hasMany(ModelWithNonGenericCollection::class);
    }

    /** @phpstan-return HasMany<ModelWithOnlyValueGenericCollection, $this> */
    public function modelsWithOnlyValueGenericCollection(): HasMany
    {
        return $this->hasMany(ModelWithOnlyValueGenericCollection::class);
    }

    /**
     * @return HasManyThrough<Transaction, Account, $this>
     */
    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, Account::class);
    }

    /**
     * @return HasManySyncable<Account, $this>
     */
    public function syncableRelation(): HasManySyncable
    {
        return $this->hasManySyncable(Account::class);
    }

    /**
     * @return MorphMany<Address, $this>
     */
    public function address(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /** @return HasOne<Address, $this> */
    public function oneAddress(): HasOne
    {
        return $this->hasOne(Address::class);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('some_column')
            ->wherePivotIn('some_column', [1, 2, 3]);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(get_class($this));
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /**
     * @return HasManySyncable<Model, $this>
     */
    public function hasManySyncable($related, $foreignKey = null, $localKey = null): HasManySyncable
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return new HasManySyncable(
            $instance->newQuery(), $this, $instance->getTable().'.'.$foreignKey, $localKey
        );
    }

    /** @return HasOne<Mechanic, $this> */
    public function mechanic(): HasOne
    {
        return $this->hasOne(Mechanic::class);
    }

    /** @return HasOneThrough<Car, Mechanic, $this> */
    public function car(): HasOneThrough
    {
        return $this->hasOneThrough(Car::class, Mechanic::class);
    }

    /** @return HasManyThrough<Part, Mechanic, $this> */
    public function parts(): HasManyThrough
    {
        return $this->hasManyThrough(Part::class, Mechanic::class);
    }

    /** @return HasOneThrough<Part, Mechanic, $this> */
    public function firstPart(): HasOneThrough
    {
        return $this->parts()->one();
    }

    public function getOnlyAvailableWithAccessorAttribute(): string
    {
        return 'foo';
    }

    public function isActive(): bool
    {
        return $this->active === 1;
    }

    public function setActive(): void
    {
        $this->active = 1;
    }

    protected function newStyleAttribute(): Attribute
    {
        return Attribute::make(
            fn ($value) => 5,
        );
    }

    /**
     * @return Attribute<int, string>
     */
    protected function stringButInt(): Attribute
    {
        return Attribute::make(
            fn ($value) => 5,
            fn (string $value) => strtolower($value)
        );
    }

    /** Unlike upstream, this takes effect although it is public and its return type has no generic types, as it does in Eloquent. */
    public function email(): Attribute
    {
        return Attribute::make(
            fn ($value) => 5,
            fn (string $value) => strtolower($value)
        );
    }

    /** @return Attribute<int, never> */
    protected function computed(): Attribute
    {
        return Attribute::get(
            fn ($value) => 5,
        );
    }
}
