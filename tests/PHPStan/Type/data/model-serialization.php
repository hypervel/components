<?php

namespace ModelSerialization;

use Carbon\Carbon;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

class SerializationModel extends Model
{
    protected ?string $table = 'users';

    protected array $visible = ['id', 'name', 'email_verified_at', 'label'];

    protected array $appends = ['label'];

    protected array $casts = ['email_verified_at' => 'datetime'];

    public function getLabelAttribute(): string
    {
        return 'label';
    }
}

// Unlike upstream, ids read int throughout: the test connection is SQLite, which does not enforce unsigned.
function serialization(SerializationModel $model): void
{
    assertType('array{id?: int, name?: string, email_verified_at?: string|null, label?: string, ...<string, mixed>}', $model->attributesToArray());
    assertType('array{id?: int, name?: string, email_verified_at?: string|null, label?: string, ...<string, mixed>}', $model->toArray());
    assertType('mixed', $model->attributesToArray()['posts_count']);
}

class HiddenSerializationModel extends SerializationModel
{
    protected array $hidden = ['name', 'label'];
}

class AccessorSerializationModel extends SerializationModel
{
    protected array $casts = ['name' => 'integer'];

    public function getNameAttribute(): bool
    {
        return true;
    }
}

enum SerializedStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum SerializedNumber: int
{
    case One = 1;
    case Two = 2;
}

enum SerializedUnit
{
    case Active;
}

class CastSerializationModel extends Model
{
    protected ?string $table = 'users';

    protected array $visible = ['backed_enum', 'basic_enum', 'integer', 'date', 'datetime', 'collection'];

    protected array $casts = [
        'backed_enum' => SerializedStatus::class,
        'basic_enum' => SerializedUnit::class,
        'integer' => SerializedNumber::class,
        'date' => 'immutable_date',
        'datetime' => 'datetime:Y-m-d',
        'collection' => 'collection',
    ];
}

/**
 * Unlike upstream, this reads a known table, limited to its appends: a model whose table is
 * unknown keeps the declared type (see UnknownTableSerializationModel).
 */
class AppendedSerializationModel extends Model
{
    protected ?string $table = 'users';

    protected array $visible = ['modern_date', 'legacy_date', 'tags', 'label'];

    protected array $appends = ['modern_date', 'legacy_date', 'tags', 'label'];

    /** @return Attribute<Carbon, never> */
    protected function modernDate(): Attribute
    {
        return Attribute::get(static fn () => new Carbon('2024-01-01'));
    }

    public function getLegacyDateAttribute(): Carbon
    {
        return new Carbon('2024-01-01');
    }

    /** @return Collection<int, string> */
    public function getTagsAttribute(): Collection
    {
        return collect(['tag']);
    }

    public function getLabelAttribute(): ?string
    {
        return null;
    }
}

class UnknownTableSerializationModel extends Model
{
    protected ?string $table = 'no_serialization_schema';

    protected array $appends = ['label'];

    public function getLabelAttribute(): string
    {
        return 'label';
    }
}

class OverriddenSerializationModel extends SerializationModel
{
    /** @return array{custom: true} */
    public function attributesToArray(): array
    {
        return ['custom' => true];
    }
}

class OverriddenToArrayModel extends SerializationModel
{
    /** @return array{custom: true} */
    public function toArray(): array
    {
        return ['custom' => true];
    }
}

class OverriddenDateModel extends SerializationModel
{
    /**
     * Unlike upstream, the override returns a narrower string: Hypervel declares serializeDate() as returning string.
     *
     * @return 'date'
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return 'date';
    }
}

function boundaries(
    HiddenSerializationModel $hidden,
    AccessorSerializationModel $accessor,
    CastSerializationModel $casts,
    AppendedSerializationModel $appended,
    OverriddenSerializationModel $override,
    OverriddenToArrayModel $toArrayOverride,
    OverriddenDateModel $dateOverride,
    Model $base,
    UnknownTableSerializationModel $unknownTable,
): void {
    assertType('array{id?: int, email_verified_at?: string|null, ...<string, mixed>}', $hidden->attributesToArray());
    assertType('mixed', $hidden->attributesToArray()['name']);
    assertType('bool', $accessor->attributesToArray()['name']);
    assertType("'active'|'inactive'", $casts->attributesToArray()['backed_enum']);
    assertType("'Active'", $casts->attributesToArray()['basic_enum']);
    assertType('1|2', $casts->attributesToArray()['integer']);
    assertType('string', $casts->attributesToArray()['date']);
    assertType('string', $casts->attributesToArray()['datetime']);
    assertType('array<mixed>', $casts->attributesToArray()['collection']);
    assertType('array{modern_date?: string, legacy_date?: Carbon\\Carbon, tags?: array<int, string>, label?: string|null, ...<string, mixed>}', $appended->attributesToArray());
    assertType('array{custom: true}', $override->attributesToArray());
    assertType('array<string, mixed>', $override->toArray());
    assertType('array{custom: true}', $toArrayOverride->toArray());
    assertType('array{id?: int, name?: string, email_verified_at?: string|null, label?: string, ...<string, mixed>}', $toArrayOverride->attributesToArray());
    assertType("'date'|null", $dateOverride->attributesToArray()['email_verified_at']);
    assertType('array<string, mixed>', $base->toArray());
    assertType('array<string, mixed>', $unknownTable->toArray());

    $partial = SerializationModel::query()->select('id')->firstOrFail();
    assertType('array{id?: int, name?: string, email_verified_at?: string|null, label?: string, ...<string, mixed>}', $partial->toArray());

    if (isset($partial->toArray()['name'])) {
        assertType('string', $partial->toArray()['name']);
    }
}

class ModernColumnSerializationModel extends SerializationModel
{
    protected array $casts = ['name' => 'integer'];

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::get(static fn () => 'name');
    }

    public function serializeInside(): void
    {
        assertType('string', $this->attributesToArray()['name']);
        assertType('string', $this->toArray()['name']);
    }
}

class SerializationValue implements Arrayable
{
    /** @return array{value: int} */
    public function toArray(): array
    {
        return ['value' => 1];
    }
}

/** @implements CastsAttributes<SerializationValue, SerializationValue> */
class SerializationValueCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): SerializationValue
    {
        return new SerializationValue();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return 'value';
    }

    /** @return array{serialized: true} */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        return ['serialized' => true];
    }
}

class CustomCastSerializationModel extends SerializationModel
{
    protected array $casts = ['name' => SerializationValueCast::class];
}

class ArrayableSerializationModel extends AppendedSerializationModel
{
    protected array $visible = ['value', 'values'];

    protected array $appends = ['value', 'values'];

    public function getValueAttribute(): SerializationValue
    {
        return new SerializationValue();
    }

    /** @return Collection<string, SerializationValue|string> */
    public function getValuesAttribute(): Collection
    {
        return collect(['first' => new SerializationValue(), 'second' => 'value']);
    }
}

interface SerializationMarker
{
}

/** @template TModel of SerializationModel */
class GenericSerialization
{
    /** @param TModel $model */
    public function serialize(Model $model): void
    {
        assertType('array{id?: int, name?: string, email_verified_at?: string|null, label?: string, ...<string, mixed>}', $model->attributesToArray());
    }
}

function additionalBoundaries(
    CustomCastSerializationModel $custom,
    ArrayableSerializationModel $arrayable,
    SerializationModel&SerializationMarker $intersection,
    ModernColumnSerializationModel|AccessorSerializationModel $union,
    ?SerializationModel $nullable,
): void {
    assertType('array{serialized: true}', $custom->attributesToArray()['name']);
    assertType('array{value: int}', $arrayable->attributesToArray()['value']);
    assertType('array<string, array{value: int}|string>', $arrayable->attributesToArray()['values']);
    assertType('array{id?: int, name?: string, email_verified_at?: string|null, label?: string, ...<string, mixed>}', $intersection->attributesToArray());
    assertType('bool|string', $union->attributesToArray()['name']);
    assertType('array{id?: int, name?: string, email_verified_at?: string|null, label?: string, ...<string, mixed>}|null', $nullable?->attributesToArray());
}

class CustomCastAccessorSerializationModel extends CustomCastSerializationModel
{
    public function getNameAttribute(): string
    {
        return 'accessor';
    }
}

function customCastAccessor(CustomCastAccessorSerializationModel $model): void
{
    assertType('array{value: int}', $model->attributesToArray()['name']);
}

/** @implements CastsAttributes<SerializationValue, SerializationValue> */
class NullableSerializationValueCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?SerializationValue
    {
        return $value === null ? null : new SerializationValue();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : 'value';
    }

    /** @return array{serialized: true} */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        return ['serialized' => true];
    }
}

class NullableCustomCastSerializationModel extends SerializationModel
{
    protected array $casts = ['name' => NullableSerializationValueCast::class];
}

// A null cast value is not serialized.
function nullableCustomCast(NullableCustomCastSerializationModel $model): void
{
    assertType('array{serialized: true}|null', $model->attributesToArray()['name']);
}

/** @implements CastsAttributes<SerializedStatus, SerializedStatus> */
class SerializationEnumCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): SerializedStatus
    {
        return SerializedStatus::Active;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return 'active';
    }
}

class CustomEnumSerializationModel extends SerializationModel
{
    protected array $casts = ['name' => SerializationEnumCast::class];
}

function customEnumSerialization(CustomEnumSerializationModel $model): void
{
    assertType('ModelSerialization\\SerializedStatus', $model->attributesToArray()['name']);
}

class DateAccessorSerializationModel extends SerializationModel
{
    public function getEmailVerifiedAtAttribute(): string
    {
        return 'verified';
    }
}

// A datetime cast is a primitive cast, not the DateTime class, so the accessor's value is serialized.
function dateAccessorSerialization(DateAccessorSerializationModel $model): void
{
    assertType('string', $model->attributesToArray()['email_verified_at']);
}

class SerializationInboundCast implements CastsInboundAttributes
{
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return 'value';
    }
}

class InboundCastAccessorSerializationModel extends SerializationModel
{
    protected array $casts = ['name' => SerializationInboundCast::class];

    public function getNameAttribute(): bool
    {
        return true;
    }
}

class AppendedCastSerializationModel extends SerializationModel
{
    protected array $visible = ['name', 'email', 'remember_token'];

    protected array $appends = ['name', 'email', 'remember_token'];

    protected array $casts = ['name' => SerializationValueCast::class, 'email' => SerializationInboundCast::class];
}

// mutateAttributeForArray() reads a class cast before any accessor and without its serialize hook, and an inbound caster
// leaves the raw value. An appended attribute without either makes it call a get{Name}Attribute() method that is missing.
function mutatedCastSerialization(InboundCastAccessorSerializationModel $accessor, AppendedCastSerializationModel $appended): void
{
    assertType('string', $accessor->attributesToArray()['name']);
    assertType('array{name?: array{value: int}, email?: string, remember_token?: mixed, ...<string, mixed>}', $appended->attributesToArray());
}

/** @extends Collection<int, string> */
class OverridingSerializationCollection extends Collection
{
    /** @return list<'custom'> */
    public function toArray(): array
    {
        return ['custom'];
    }
}

/** @extends Collection<int, SerializationValue> */
class InheritingSerializationCollection extends Collection
{
}

class CustomCollectionSerializationModel extends AppendedSerializationModel
{
    protected array $visible = ['overriding', 'inheriting'];

    protected array $appends = ['overriding', 'inheriting'];

    public function getOverridingAttribute(): OverridingSerializationCollection
    {
        return new OverridingSerializationCollection(['value']);
    }

    public function getInheritingAttribute(): InheritingSerializationCollection
    {
        return new InheritingSerializationCollection([new SerializationValue()]);
    }
}

function customCollectionSerialization(CustomCollectionSerializationModel $model): void
{
    assertType("list<'custom'>", $model->attributesToArray()['overriding']);
    assertType('array<int, array{value: int}>', $model->attributesToArray()['inheriting']);
}
