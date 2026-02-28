<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Concerns;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::flushCasterCache();
    }

    public function testGetCastsIncludesCastsMethodForIncrementingModels(): void
    {
        $model = new HasAttributesIncrementingModel();

        $casts = $model->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertArrayHasKey('data', $casts);
        $this->assertSame('array', $casts['data']);
    }

    public function testGetCastsIncludesCastsMethodForNonIncrementingModels(): void
    {
        $model = new HasAttributesUuidModel();

        $casts = $model->getCasts();

        $this->assertArrayHasKey('data', $casts);
        $this->assertSame('array', $casts['data']);
    }

    public function testGetCastsMergesPropertyAndMethodForNonIncrementingModels(): void
    {
        $model = new HasAttributesMixedCastsModel();

        $casts = $model->getCasts();

        // From $casts property
        $this->assertArrayHasKey('config', $casts);
        $this->assertSame('array', $casts['config']);

        // From casts() method
        $this->assertArrayHasKey('data', $casts);
        $this->assertSame('array', $casts['data']);
    }

    public function testResolveCasterClassReturnsSameInstanceOnSubsequentCalls()
    {
        $model = new CasterCacheModel();
        $invoker = new ClassInvoker($model);

        $first = $invoker->resolveCasterClass('data');
        $second = $invoker->resolveCasterClass('data');

        $this->assertSame($first, $second);
    }

    public function testResolveCasterClassCachesPerModelClass()
    {
        $modelA = new CasterCacheModel();
        $modelB = new CasterCacheModelB();

        $casterA = (new ClassInvoker($modelA))->resolveCasterClass('data');
        $casterB = (new ClassInvoker($modelB))->resolveCasterClass('data');

        // Same caster class, but different cache entries per model class,
        // so they are distinct instances.
        $this->assertInstanceOf(UppercaseCaster::class, $casterA);
        $this->assertInstanceOf(UppercaseCaster::class, $casterB);
        $this->assertNotSame($casterA, $casterB);
    }

    public function testResolveCasterClassCachesCasterWithArguments()
    {
        $model = new CasterCacheWithArgumentsModel();
        $invoker = new ClassInvoker($model);

        $first = $invoker->resolveCasterClass('amount');
        $second = $invoker->resolveCasterClass('amount');

        $this->assertInstanceOf(ParameterizedCaster::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame(['2'], $first->arguments);
    }

    public function testResolveCasterClassCachesDifferentCastTypesSeperately()
    {
        $model = new CasterCacheMultipleCastsModel();
        $invoker = new ClassInvoker($model);

        $dataCaster = $invoker->resolveCasterClass('data');
        $amountCaster = $invoker->resolveCasterClass('amount');

        $this->assertInstanceOf(UppercaseCaster::class, $dataCaster);
        $this->assertInstanceOf(ParameterizedCaster::class, $amountCaster);
        $this->assertNotSame($dataCaster, $amountCaster);
    }

    public function testResolveCasterClassCachesCastableClass()
    {
        $model = new CasterCacheCastableModel();
        $invoker = new ClassInvoker($model);

        $first = $invoker->resolveCasterClass('data');
        $second = $invoker->resolveCasterClass('data');

        $this->assertInstanceOf(UppercaseCaster::class, $first);
        $this->assertSame($first, $second);
    }

    public function testResolveCasterClassCachesCastableReturningObject()
    {
        $model = new CasterCacheCastableObjectModel();
        $invoker = new ClassInvoker($model);

        $first = $invoker->resolveCasterClass('data');
        $second = $invoker->resolveCasterClass('data');

        $this->assertInstanceOf(UppercaseCaster::class, $first);
        $this->assertSame($first, $second);
    }
}

class HasAttributesIncrementingModel extends Model
{
    protected ?string $table = 'test_models';

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}

class HasAttributesUuidModel extends Model
{
    use HasUuids;

    protected ?string $table = 'test_models';

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}

class HasAttributesMixedCastsModel extends Model
{
    use HasUuids;

    protected ?string $table = 'test_models';

    protected array $casts = [
        'config' => 'array',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}

class UppercaseCaster implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return strtoupper((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}

class ParameterizedCaster implements CastsAttributes
{
    /** @var string[] */
    public array $arguments;

    public function __construct(string ...$arguments)
    {
        $this->arguments = $arguments;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}

class CastableUppercase implements Castable
{
    public static function castUsing(array $arguments): string
    {
        return UppercaseCaster::class;
    }
}

class CastableUppercaseObject implements Castable
{
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new UppercaseCaster();
    }
}

class CasterCacheModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'data' => UppercaseCaster::class,
    ];
}

class CasterCacheModelB extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'data' => UppercaseCaster::class,
    ];
}

class CasterCacheWithArgumentsModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'amount' => ParameterizedCaster::class . ':2',
    ];
}

class CasterCacheMultipleCastsModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'data' => UppercaseCaster::class,
        'amount' => ParameterizedCaster::class . ':2',
    ];
}

class CasterCacheCastableModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'data' => CastableUppercase::class,
    ];
}

class CasterCacheCastableObjectModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'data' => CastableUppercaseObject::class,
    ];
}
