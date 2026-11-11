<?php

namespace ModelCastWrites;

use DateTimeImmutable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;

use function PHPStan\Testing\assertType;

function testEnumWrites(EnumCastModel $model): void
{
    $model->int = IntStatus::One;
    $model->int = 1;
    $model->int = '1';
    $model->int = 'one';
    $model->string = StringStatus::One;
    $model->string = '1';
    $model->string = 1;
    $model->integer = PureStatus::One;
    $model->integer = 'One';
    $model->integer = 1;
}

function testCasterNullability(CasterModel $model): void
{
    assertType('ModelCastWrites\Label', $model->nullable_collection);
    $model->nullable_collection = new Label('text');
    $model->nullable_collection = null;
}

function testUnknownNullability(UnknownTableModel $model): void
{
    $model->count = 1;
    $model->count = null;
}

// A custom cast on the soft-delete column replaces its datetime cast, so writes follow the caster.
function testSoftDeleteColumnCast(SoftDeleteCastModel $model): void
{
    assertType('ModelCastWrites\Label', $model->deleted_at);
    $model->deleted_at = new Label('text');
    $model->deleted_at = new DateTimeImmutable();
}

enum IntStatus: int
{
    case One = 1;
}

enum StringStatus: string
{
    case One = '1';
}

enum PureStatus
{
    case One;
}

#[Table('model_with_casts')]
class EnumCastModel extends Model
{
    protected array $casts = [
        'int' => IntStatus::class,
        'string' => StringStatus::class,
        'integer' => PureStatus::class,
    ];
}

#[Table('model_with_casts')]
class CasterModel extends Model
{
    protected array $casts = [
        'nullable_collection' => LabelCast::class,
    ];
}

#[Table('model_with_casts')]
class SoftDeleteCastModel extends Model
{
    use SoftDeletes;

    protected array $casts = [
        'deleted_at' => LabelCast::class,
    ];
}

#[Table('missing_table')]
class UnknownTableModel extends Model
{
    protected array $casts = [
        'count' => 'int',
    ];
}

/**
 * @implements CastsAttributes<Label, Label>
 */
class LabelCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Label
    {
        return new Label(is_string($value) ? $value : '');
    }

    /**
     * @param Label $value
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value->text;
    }
}

class Label
{
    public function __construct(public string $text)
    {
    }
}
