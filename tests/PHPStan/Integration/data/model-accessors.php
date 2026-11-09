<?php

declare(strict_types=1);

namespace ModelAccessors;

use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

#[Table('users')]
class AccessorModel extends Model
{
    protected array $casts = ['virtual' => 'integer', 'blocked' => 'boolean'];

    /** @return Attribute<int, never> */
    protected function name(): Attribute
    {
        return Attribute::get(static fn (): int => 1);
    }

    /** @return Attribute<never, int> */
    protected function email(): Attribute
    {
        return Attribute::set(static fn (int $value): string => (string) $value);
    }

    /** @return Attribute<never, string> */
    protected function virtual(): Attribute
    {
        return Attribute::set(static fn (string $value): int => (int) $value);
    }

    /** @return Attribute<never, bool> */
    protected function blocked(): Attribute
    {
        return Attribute::set(static fn (bool $value): bool => $value);
    }

    public function setPasswordAttribute(int $value): void
    {
        $this->attributes['password'] = (string) $value;
    }

    public function getSecretAttribute(): int
    {
        return 1;
    }

    public function secret(): string
    {
        return 'unrelated';
    }
}

#[Table('users')]
class ParentAccessorModel extends Model
{
    public function getStringAttribute(): int
    {
        return 1;
    }
}

class ChildAccessorModel extends ParentAccessorModel
{
    /** @return Attribute<float, never> */
    protected function string(): Attribute
    {
        return Attribute::get(static fn (): float => 1.5);
    }
}

function accessors(AccessorModel $model, ChildAccessorModel $child): void
{
    // A get-only Attribute reads its getter's type; Eloquent writes the column directly.
    assertType('int', $model->name);
    $model->name = 'name';
    $model->name = 1;

    // A set-only Attribute accepts its setter's input; Eloquent reads the column directly.
    assertType('string', $model->email);
    $model->email = 1;
    $model->email = 'email';

    // A virtual attribute without a getter reads its raw value through its cast.
    assertType('int', $model->virtual);
    $model->virtual = 'value';
    $model->virtual = 1;

    // A legacy setter types writes by its parameter.
    $model->password = 1;
    $model->password = 'password';

    // A legacy getter applies beside a method that is not an Attribute accessor.
    assertType('int', $model->secret);

    // Eloquent finds an inherited legacy getter before the child's Attribute getter.
    assertType('int', $child->string);

    // A set-only Attribute serializes through the column's cast.
    assertType('bool|null', $model->attributesToArray()['blocked'] ?? null);
}
