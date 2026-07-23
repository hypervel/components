<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;

class DatabaseSoftDeletingTest extends TestCase
{
    public function testDeletedAtIsAddedToCastsAsDefaultType()
    {
        $model = new SoftDeletingModel;

        $this->assertArrayHasKey('deleted_at', $model->getCasts());
        $this->assertSame('datetime', $model->getCasts()['deleted_at']);
    }

    public function testDeletedAtIsCastToCarbonImmutableByDefault(): void
    {
        $expected = CarbonImmutable::createFromFormat('Y-m-d H:i:s', '2018-12-29 13:59:39');
        $model = new SoftDeletingModel(['deleted_at' => $expected->format('Y-m-d H:i:s')]);

        $this->assertSame(CarbonImmutable::class, $model->deleted_at::class);
        $this->assertTrue($expected->eq($model->deleted_at));
    }

    public function testExistingCastOverridesAddedDateCast()
    {
        $model = new class(['deleted_at' => '2018-12-29 13:59:39']) extends SoftDeletingModel {
            protected array $casts = ['deleted_at' => 'bool'];
        };

        $this->assertTrue($model->deleted_at);
    }

    public function testExistingMutatorOverridesAddedDateCast()
    {
        $model = new class(['deleted_at' => '2018-12-29 13:59:39']) extends SoftDeletingModel {
            protected function getDeletedAtAttribute()
            {
                return 'expected';
            }
        };

        $this->assertSame('expected', $model->deleted_at);
    }

    public function testCastingToStringOverridesAutomaticDateCastingToRetainPreviousBehaviour()
    {
        $model = new class(['deleted_at' => '2018-12-29 13:59:39']) extends SoftDeletingModel {
            protected array $casts = ['deleted_at' => 'string'];
        };

        $this->assertSame('2018-12-29 13:59:39', $model->deleted_at);
    }
}

class SoftDeletingModel extends Model
{
    use SoftDeletes;

    protected array $guarded = [];

    protected ?string $dateFormat = 'Y-m-d H:i:s';
}
