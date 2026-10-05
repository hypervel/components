<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class DummyModel extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'date' => 'datetime',
        'nullable_date' => 'datetime',
        'optional_date' => 'datetime',
        'nullable_optional_date' => 'datetime',
        'boolean' => 'boolean',
    ];

    /**
     * Create the dummy model table.
     */
    public static function migrate(): void
    {
        Schema::create('dummy_models', function (Blueprint $blueprint): void {
            $blueprint->increments('id');

            $blueprint->string('string');
            $blueprint->dateTime('date');
            $blueprint->dateTime('nullable_date')->nullable();
            $blueprint->dateTime('optional_date')->nullable();
            $blueprint->dateTime('nullable_optional_date')->nullable();
            $blueprint->boolean('boolean');

            $blueprint->timestamps();
        });
    }
}
