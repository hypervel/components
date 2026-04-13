<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasUuidsTest extends TestCase
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

    public function testAutoGeneratesUuidWhenNotProvided(): void
    {
        $model = HasUuidsTestModel::create(['name' => 'Test']);

        $this->assertNotNull($model->id);
        $this->assertTrue(Str::isUuid($model->id));
    }

    public function testRespectsExplicitUuidWhenProvided(): void
    {
        $explicitId = (string) Str::orderedUuid();

        $model = HasUuidsTestModel::create([
            'id' => $explicitId,
            'name' => 'Test',
        ]);

        $this->assertSame($explicitId, $model->id);
    }

    public function testRespectsExplicitUuidInFirstOrCreate(): void
    {
        $explicitId = (string) Str::orderedUuid();

        $model = HasUuidsTestModel::firstOrCreate(
            ['id' => $explicitId],
            ['name' => 'Test']
        );

        $this->assertSame($explicitId, $model->id);
    }

    public function testDoesNotOverwriteIdOnExistingRecord(): void
    {
        $explicitId = (string) Str::orderedUuid();

        // Create first
        HasUuidsTestModel::create([
            'id' => $explicitId,
            'name' => 'Original',
        ]);

        // firstOrCreate should find existing, not create new
        $model = HasUuidsTestModel::firstOrCreate(
            ['id' => $explicitId],
            ['name' => 'Should Not Be Used']
        );

        $this->assertSame($explicitId, $model->id);
        $this->assertSame('Original', $model->name);
    }
}

class HasUuidsTestModel extends Model
{
    use HasUuids;

    protected ?string $table = 'has_uuids_test_models';

    protected array $fillable = ['id', 'name'];

    public bool $timestamps = true;
}
