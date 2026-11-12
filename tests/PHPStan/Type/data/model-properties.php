<?php

namespace ModelProperties;

use App\Account;
use App\Address;
use App\Casts\BackedEnumeration;
use App\Casts\BasicEnumeration;
use App\Group;
use App\GuardedModel;
use App\Role;
use App\RoleUser;
use App\Team;
use App\Thread;
use App\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use UnitEnum;

use function PHPStan\Testing\assertType;

function test(
    User $user,
    Account $account,
    Role $role,
    Group $group,
    Team $team,
    GuardedModel $guardedModel,
    Thread $thread,
    Address $address,
    RoleUser $roleUser,
    ModelWithCasts $modelWithCasts
): void {
    // Unlike upstream, the test connection is SQLite, which does not enforce unsigned, so unsigned
    // columns read int. Dates read CarbonInterface because asDateTime() only guarantees the interface.

    // Unlike upstream, a bare Attribute still applies, as in Eloquent, and reads mixed;
    // missingType.generics reports its missing generics.
    assertType('mixed', $user->newStyleAttribute);
    assertType('int', $user->stringButInt);
    // Unlike upstream, User::email() is an accessor Eloquent applies, so email reads as its untyped callback.
    assertType('mixed', $user->email);
    assertType('string|null', $user->remember_token);
    assertType('array', $user->allowed_ips);
    assertType('numeric-string', $user->floatButRoundedDecimalString);
    assertType('int', $user->unsigned_integer);
    assertType('int', $user->unsigned_integer_method);

    // Model Casts
    assertType('int', $user->int);
    assertType('int', $user->integer);
    assertType('float', $user->real);
    assertType('float', $user->float);
    assertType('float', $user->double);
    assertType('numeric-string', $user->decimal);
    assertType('numeric-string|null', $user->nullable_decimal);
    assertType('string', $user->string);
    assertType('bool', $user->bool);
    assertType('bool', $user->boolean);
    assertType('stdClass', $user->object);
    assertType('array', $user->array);
    assertType('array', $user->json);
    assertType(Collection::class, $user->collection);
    assertType(Collection::class.'|null', $user->nullable_collection);
    assertType(CarbonInterface::class, $user->date);
    assertType(CarbonInterface::class, $user->datetime);
    assertType(CarbonImmutable::class, $user->immutable_date);
    assertType(CarbonImmutable::class, $user->immutable_datetime);
    assertType('int', $user->timestamp);
    assertType("'active'|'don\'t know'|'inactive'", $user->enum_status);
    assertType(BasicEnumeration::class, $user->basic_enum);
    assertType(BackedEnumeration::class, $user->backed_enum);

    // Castable
    assertType(Stringable::class, $user->castable_with_argument);

    // CastsAttributes
    assertType('App\ValueObjects\Favorites', $user->favorites);

    assertType('int', $user->id);
    assertType('bool', $user->blocked);
    assertType('Carbon\CarbonInterface|null', $user->created_at);
    assertType('array', $user->meta);
    assertType('string', $account->active);
    assertType('string', $role->id);
    assertType('string', $team->id);
    assertType('int', $group->id);
    assertType('string', $guardedModel->name);
    assertType('string', $thread->custom_property);
    // Hypervel: the caster reads a missing or non-array value as null.
    assertType('(Hypervel\Database\Eloquent\Casts\ArrayObject|null)', $user->options);
    assertType('int<0, max>', count($user->options));
    assertType('(Hypervel\Support\Collection<(int|string), mixed>|null)', $user->properties);
    assertType('int<0, max>', count($user->properties));
    assertType('mixed', $user->properties->first());
    assertType('Hypervel\Support\Collection<(int|string), App\Casts\BackedEnumeration>|null', $user->enum_collection);
    assertType('App\Casts\BackedEnumeration|null', $user->enum_collection?->first());
    assertType('Hypervel\Database\Eloquent\Casts\ArrayObject<(int|string), App\Casts\BasicEnumeration>|null', $user->enum_array_object);
    assertType('non-falsy-string|null', $user->deleted_at?->format('d/m/Y'));
    assertType('int', $address->user_id);
    assertType('int', $address->custom_foreign_id_for_name);
    assertType('string', $address->address_id);
    assertType('string', $address->nullable_address_id); // overridden by a @property
    assertType('int', $address->foreign_id_constrained);
    assertType('int|null', $address->nullable_foreign_id_constrained);
    assertType('string', $address->foreign_uuid);
    assertType('string|null', $address->nullable_foreign_uuid);
    assertType('string', $address->foreign_ulid);
    assertType('string|null', $address->nullable_foreign_ulid);
    assertType('App\ValueObjects\Favorites', $user->favorites);
    assertType('string', $address->uuid);
    assertType('string', $roleUser->role_id);
    assertType('int', $roleUser->user_id);

    assertType('bool', $modelWithCasts->integer);
    assertType(Stringable::class, $modelWithCasts->string);
}

function testUnknownSchema(UnknownSchemaUser $user): void
{
    // Unknown schema facts do not hide a declared relation, and only a trailing _count names a relation count.
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Post&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->posts);
    assertType('int<0, max>', $user->stock_count_items_count);
    assertType('mixed', $user->name);

    // Hypervel: a relation method named like a count is a relation, as the model reads it.
    assertType('App\AccountCollection<int, App\Account>', $user->item_count);
    assertType('App\AccountCollection<int, App\Account>', $user->lone_count);
}

class UnknownSchemaUser extends User
{
    protected UnitEnum|string|null $connection = 'unconfigured';

    /**
     * @return HasMany<Account, $this>
     */
    public function stock_count_items(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function item(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function item_count(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function lone_count(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}

class ModelWithCasts extends Model
{
    protected array $casts = [
        'integer' => 'int',
    ];

    /**
     * @return array{integer: 'bool', string: 'Hypervel\\Database\\Eloquent\\Casts\\AsStringable:argument'}
     */
    public function casts(): array
    {
        $argument = 'argument';

        return [
            'integer' => 'bool', // overrides the cast from the property
            'string' => AsStringable::class.':'.$argument,
        ];
    }
}
