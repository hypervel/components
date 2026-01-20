<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasAttributesTest extends TestCase
{
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
