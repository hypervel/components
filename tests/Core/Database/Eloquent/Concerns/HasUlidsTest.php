<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Hyperf\Stringable\Str;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasUlidsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    public function testAutoGeneratesUlidWhenNotProvided(): void
    {
        $model = HasUlidsTestModel::create(['name' => 'Test']);

        $this->assertNotNull($model->id);
        $this->assertTrue(Str::isUlid($model->id));
    }

    public function testRespectsExplicitUlidWhenProvided(): void
    {
        $explicitId = strtolower((string) Str::ulid());

        $model = HasUlidsTestModel::create([
            'id' => $explicitId,
            'name' => 'Test',
        ]);

        $this->assertSame($explicitId, $model->id);
    }

    public function testRespectsExplicitUlidInFirstOrCreate(): void
    {
        $explicitId = strtolower((string) Str::ulid());

        $model = HasUlidsTestModel::firstOrCreate(
            ['id' => $explicitId],
            ['name' => 'Test']
        );

        $this->assertSame($explicitId, $model->id);
    }
}

class HasUlidsTestModel extends Model
{
    use HasUlids;

    protected ?string $table = 'has_ulids_test_models';

    protected array $fillable = ['id', 'name'];

    public bool $timestamps = true;
}
