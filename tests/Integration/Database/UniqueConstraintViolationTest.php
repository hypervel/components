<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;

class UniqueConstraintViolationTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_unique_constraint', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique('single_unique_idx');
        });

        Schema::create('test_unique_constraint_composite', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');

            $table->unique(['first_name', 'last_name'], 'unique_composite_idx');
        });
    }

    private function createUniqueModel(): UniqueConstraintViolationException
    {
        UniqueSingleModel::query()->create(['name' => 'test']);
        try {
            UniqueSingleModel::query()->create(['name' => 'test']);
        } catch (UniqueConstraintViolationException $e) {
            return $e;
        }

        $this->fail('No exception was thrown');
    }

    private function createCompositeModel(): UniqueConstraintViolationException
    {
        UniqueCompositeModel::query()->create(['first_name' => 'Taylor', 'last_name' => 'Otwell']);
        try {
            UniqueCompositeModel::query()->create(['first_name' => 'Taylor', 'last_name' => 'Otwell']);
        } catch (UniqueConstraintViolationException $e) {
            return $e;
        }

        $this->fail('No exception was thrown');
    }

    #[RequiresDatabase('sqlite')]
    public function testSqliteUniqueConstraint(): void
    {
        $e = $this->createUniqueModel();
        $this->assertSame(['name'], $e->columns);
        $this->assertNull($e->index);
    }

    #[RequiresDatabase('sqlite')]
    public function testSqliteUniqueCompositeConstraint(): void
    {
        $e = $this->createCompositeModel();
        $this->assertSame(['first_name', 'last_name'], $e->columns);
        $this->assertNull($e->index);
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    public function testMysqlUniqueConstraint(): void
    {
        $e = $this->createUniqueModel();
        $this->assertSame('single_unique_idx', $e->index);
        $this->assertSame([], $e->columns);
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    public function testMysqlUniqueCompositeConstraint(): void
    {
        $e = $this->createCompositeModel();
        $this->assertSame('unique_composite_idx', $e->index);
        $this->assertSame([], $e->columns);
    }

    #[RequiresDatabase('pgsql')]
    public function testPostgresUniqueConstraint(): void
    {
        $e = $this->createUniqueModel();
        $this->assertSame('single_unique_idx', $e->index);
        $this->assertSame(['name'], $e->columns);
    }

    #[RequiresDatabase('pgsql')]
    public function testPostgresUniqueCompositeConstraint(): void
    {
        $e = $this->createCompositeModel();
        $this->assertSame('unique_composite_idx', $e->index);
        $this->assertSame(['first_name', 'last_name'], $e->columns);
    }
}

class UniqueSingleModel extends Model
{
    protected ?string $table = 'test_unique_constraint';

    protected array $fillable = ['name'];

    public bool $timestamps = false;
}

class UniqueCompositeModel extends Model
{
    protected ?string $table = 'test_unique_constraint_composite';

    protected array $fillable = ['first_name', 'last_name'];

    public bool $timestamps = false;
}
