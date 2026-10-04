<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Data\DataCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractData;
use Hypervel\Tests\Data\Fixtures\LazyData;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class DummyModelWithCasts extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'data' => SimpleData::class,
        'lazy_data' => LazyData::class,
        'data_collection' => DataCollection::class . ':' . SimpleData::class,
        'lazy_data_collection' => DataCollection::class . ':' . LazyData::class,
        'abstract_data' => AbstractData::class,
        'abstract_collection' => DataCollection::class . ':' . AbstractData::class,
    ];

    public bool $timestamps = false;

    /**
     * Create the model's table.
     */
    public static function migrate(): void
    {
        Schema::create('dummy_model_with_casts', function (Blueprint $blueprint): void {
            $blueprint->increments('id');

            $blueprint->text('data')->nullable();
            $blueprint->text('lazy_data')->nullable();
            $blueprint->text('data_collection')->nullable();
            $blueprint->text('lazy_data_collection')->nullable();
            $blueprint->text('abstract_data')->nullable();
            $blueprint->text('abstract_collection')->nullable();
        });
    }
}
