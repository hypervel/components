<?php

namespace ModelCastsParsing;

use App\Casts\BackedEnumeration;
use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsEnumCollection;
use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use function PHPStan\Testing\assertType;

function test(ModelWithCasts $model): void
{
    assertType('bool', $model->integer);
    assertType(Stringable::class, $model->string);
}

class ModelWithCasts extends Model
{
    public function foo(): int
    {
        return 5;
    }

    public function casts(): array
    {
        return [
            'integer' => 'bool',
            'string' => AsStringable::class.':argument',
        ];
    }
}

function testRegressions(
    ChildModel $child,
    SecondModel $second,
    TraitModel $trait,
    NestedClosureModel $nestedClosure,
    HelperModel $helpers,
    ConstructedCastModel $constructed,
    MultipleReturnsModel $multipleReturns,
    OpenShapeModel $openShape,
    UnknownCastsModel $unknown,
    MissingTableModel $missingTable,
    PropertyArrayCastModel $propertyArrays,
    VirtualCastModel $virtual,
): void {
    // self:: in an inherited casts() refers to the class declaring it.
    assertType('bool', $child->integer);

    // Each model reads its own casts(), even when another model in the file declares one first.
    assertType('float', $second->integer);

    assertType('float', $trait->integer);

    // A nested closure's return is not the method's.
    assertType('float', $nestedClosure->integer);

    assertType('Hypervel\Support\Collection<(int|string), App\Casts\BackedEnumeration>|null', $helpers->collection);
    assertType('(Hypervel\Support\Collection<(int|string), mixed>|null)', $helpers->nullable_collection);
    // The selected collection class is kept, unless a mapper may return another.
    assertType('(ModelCastsParsing\OptionCollection<(int|string), mixed>|null)', $helpers->object);
    assertType('(Hypervel\Support\Collection<(int|string), mixed>|null)', $helpers->bool);
    assertType('(Hypervel\Database\Eloquent\Casts\ArrayObject|null)', $helpers->json);
    assertType('Hypervel\Support\Collection<(int|string), App\Casts\BackedEnumeration>|null', $helpers->array);
    assertType('mixed', $helpers->string);
    assertType('float', $helpers->float);
    $helpers->string = 'value';
    assertType('mixed', $helpers->string);

    // The cast is read from the declared return type, without constructing the model or calling casts().
    assertType(Stringable::class, $constructed->string);

    // A method with several returns is read from its declared return type.
    assertType('bool', $multipleReturns->integer);
    assertType('mixed', $multipleReturns->string);
    assertType('float', $multipleReturns->float);

    // An open shape leaves the other attributes' casts unknown.
    assertType('bool', $openShape->integer);
    assertType('mixed', $openShape->float);

    assertType('mixed', $unknown->integer);
    assertType('mixed', $unknown->float);
    // The cast may be a class cast, which serializes before the accessor.
    assertType('mixed', $unknown->toArray()['real']);

    // A key without a known column is still cast.
    assertType('string', $missingTable->id);

    // Array definitions in the $casts property are normalized as initializeHasAttributes() does.
    assertType('(Hypervel\Database\Eloquent\Casts\ArrayObject|null)', $propertyArrays->json);
    assertType('numeric-string', $propertyArrays->decimal);

    // A cast key is an attribute without a column of its own.
    assertType('ModelCastsParsing\Dimensions', $virtual->dimensions);
}

function testCasters(CastableModel $castable, MissingTableModel $missingTable, VirtualCastModel $virtual): void
{
    // castUsing() selects one caster by class name.
    assertType('ModelCastsParsing\Dimensions', $castable->real);
    // A castUsing() that may return several casters leaves the type unknown.
    assertType('mixed', $castable->float);

    // An attribute without a known column may be null.
    assertType('int|null', $missingTable->count);
    assertType('int|null', $virtual->quantity);
}

function testHelpers(): void
{
    assertType("'Hypervel\\\\Database\\\\Eloquent\\\\Casts\\\\AsEnumCollection:App\\\\Casts\\\\BackedEnumeration'", AsEnumCollection::of(BackedEnumeration::class));
    assertType("'Hypervel\\\\Database\\\\Eloquent\\\\Casts\\\\AsCollection:,,nullable'", AsCollection::nullable(null));
    assertType("'Hypervel\\\\Database\\\\Eloquent\\\\Casts\\\\AsCollection:,App\\\\Casts\\\\BackedEnumeration@from'", AsCollection::of([BackedEnumeration::class, 'from']));
    assertType('string', AsCollection::of(['not', 'callable']));
    assertType('string', AsEnumCollection::of(123));
    assertType('string', CollectionCast::of(BackedEnumeration::class));
}

class CollectionCast extends AsCollection
{
}

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class OptionCollection extends Collection
{
}

class ParentModel extends Model
{
    public const string CAST = 'bool';

    protected ?string $table = 'model_with_casts';

    protected function casts(): array
    {
        return [
            'integer' => self::CAST,
        ];
    }
}

class ChildModel extends ParentModel
{
    public const string CAST = 'array';
}

class SecondModel extends Model
{
    protected ?string $table = 'model_with_casts';

    protected function casts(): array
    {
        return [
            'integer' => 'float',
        ];
    }
}

class TraitModel extends Model
{
    use CastsIntegerAsFloat;

    protected ?string $table = 'model_with_casts';
}

class NestedClosureModel extends Model
{
    protected ?string $table = 'model_with_casts';

    protected function casts(): array
    {
        $unused = static function (): array {
            return ['integer' => 'bool'];
        };

        return [
            'integer' => 'float',
        ];
    }
}

class HelperModel extends Model
{
    protected ?string $table = 'model_with_casts';

    protected function casts(): array
    {
        $cast = 'array';

        return [
            'collection' => AsEnumCollection::of(BackedEnumeration::class),
            'nullable_collection' => AsCollection::using(Collection::class),
            'object' => AsCollection::using(OptionCollection::class),
            'bool' => AsCollection::using(OptionCollection::class, Dimensions::class),
            'json' => AsArrayObject::nullable(),
            'array' => [AsEnumCollection::class, BackedEnumeration::class],
            'string' => $cast,
        ];
    }
}

class ConstructedCastModel extends Model
{
    protected ?string $table = 'model_with_casts';

    private string $castClass;

    public function __construct(array $attributes = [])
    {
        $this->castClass = AsStringable::class;

        parent::__construct($attributes);
    }

    /**
     * @return array{string: 'Hypervel\\Database\\Eloquent\\Casts\\AsStringable'}
     */
    protected function casts(): array
    {
        return [
            'string' => $this->castClass,
        ];
    }
}

class MultipleReturnsModel extends Model
{
    protected ?string $table = 'model_with_casts';

    /**
     * @return array{integer: 'bool', string?: 'array'}
     */
    protected function casts(): array
    {
        if ($this->exists) {
            return ['integer' => 'bool', 'string' => 'array'];
        }

        return ['integer' => 'bool'];
    }
}

class OpenShapeModel extends Model
{
    protected ?string $table = 'model_with_casts';

    /**
     * @return array{integer: 'bool', ...}
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'integer' => 'bool'];
    }
}

class UnknownCastsModel extends Model
{
    protected ?string $table = 'model_with_casts';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['integer' => 'bool']);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function real(): Attribute
    {
        return Attribute::get(fn (float $value): string => (string) $value);
    }
}

class MissingTableModel extends Model
{
    protected ?string $table = 'missing_table';

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'count' => 'int',
        ];
    }
}

class PropertyArrayCastModel extends Model
{
    protected ?string $table = 'model_with_casts';

    protected array $casts = [
        'json' => [AsArrayObject::class],
        'decimal' => ['decimal', 1],
    ];
}

class VirtualCastModel extends Model
{
    protected ?string $table = 'model_with_casts';

    protected array $casts = [
        'dimensions' => DimensionsCast::class,
        'quantity' => 'int',
    ];
}

class CastableModel extends Model
{
    protected ?string $table = 'model_with_casts';

    protected array $casts = [
        'real' => DimensionsCastable::class,
        'float' => AmbiguousCastable::class,
    ];
}

class DimensionsCastable implements Castable
{
    /**
     * @return class-string<DimensionsCast>
     */
    public static function castUsing(array $arguments): string
    {
        return DimensionsCast::class;
    }
}

class AmbiguousCastable implements Castable
{
    /**
     * @return DimensionsCast|StringableCast
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return $arguments === [] ? new DimensionsCast : new StringableCast;
    }
}

/**
 * @implements CastsAttributes<Stringable, string>
 */
class StringableCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Stringable
    {
        return new Stringable((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return (string) $value;
    }
}

/**
 * @implements CastsAttributes<Dimensions, Dimensions>
 */
class DimensionsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Dimensions
    {
        return new Dimensions($attributes['real'], $attributes['float']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return ['real' => $value->width, 'float' => $value->height];
    }
}

class Dimensions
{
    public function __construct(public float $width, public float $height)
    {
    }
}
