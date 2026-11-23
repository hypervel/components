<?php

declare(strict_types=1);

namespace ModelAppendsHypervel;

use App\Casts\BackedEnumeration;
use App\Casts\Favorites;
use Hypervel\Database\Eloquent\Attributes\Appends;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;

#[Appends([
    'favorites',
    'missing',
    'email',
])]
class Declarations extends Model
{
    protected ?string $table = 'users';

    protected array $appends = [
        'password',
        'backed_enum',
        'computed',
    ];

    protected array $casts = [
        'favorites' => Favorites::class,
        'backed_enum' => BackedEnumeration::class,
    ];

    public function getComputedAttribute(): string
    {
        return 'computed';
    }

    /** @return Attribute<never, string> */
    protected function password(): Attribute
    {
        return Attribute::set(fn (string $value): string => $value);
    }
}

class ConstantAppends extends Model
{
    private const array APPENDS = ['name'];

    protected ?string $table = 'users';

    protected array $appends = self::APPENDS;
}

#[Appends('first_name', 'undetermined_cast')]
class VariadicAppends extends Model
{
    protected ?string $table = 'users';

    protected function casts(): array
    {
        return ['undetermined_cast' => $this->castClass()];
    }

    protected function firstName(): Attribute
    {
        return Attribute::get(fn (): string => 'first');
    }

    private function castClass(): string
    {
        return Favorites::class;
    }
}

abstract class AppendsFullName extends Model
{
    protected ?string $table = 'users';

    protected array $appends = ['full_name'];
}

class WithFullName extends AppendsFullName
{
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => 'full');
    }
}

class WithoutFullName extends AppendsFullName
{
}

#[Appends(['trait_name'])]
trait AppendsTraitName
{
}

class UsesTraitAppends extends Model
{
    use AppendsTraitName;

    protected ?string $table = 'users';
}

class CustomSerialization extends Model
{
    protected ?string $table = 'users';

    protected array $appends = ['anything'];

    protected function mutateAttributeForArray(string $key, mixed $value): mixed
    {
        return $key === 'anything' ? 'value' : parent::mutateAttributeForArray($key, $value);
    }
}

class SharedDeclaration extends Model
{
    protected ?string $table = 'users';

    protected array $hidden = [], $appends = ['shared_missing'];
}

class AppendsDateCast extends Model
{
    protected ?string $table = 'users';

    protected array $appends = ['email_verified_at'];

    protected array $casts = ['email_verified_at' => 'datetime'];
}

class CustomArraySerialization extends Model
{
    protected ?string $table = 'users';

    protected array $appends = ['anything'];

    public function attributesToArray(): array
    {
        return ['anything' => 'value'];
    }
}

class SpreadAppends extends Model
{
    protected const array APPENDS = ['missing_spread'];

    protected ?string $table = 'users';

    protected array $appends = [
        ...self::APPENDS,
        'missing_item',
    ];
}
