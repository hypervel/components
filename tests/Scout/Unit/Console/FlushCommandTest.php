<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Scout;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

class FlushCommandTest extends ScoutTestCase
{
    public function testFlushCommandExplicitlyForcesTheModelFlush(): void
    {
        $guarded = false;
        Scout::guardModelFlushUsing(function (Model $model, Engine $engine, bool $force) use (&$guarded): void {
            $this->assertInstanceOf(SearchableModel::class, $model);
            $this->assertTrue($force);
            $guarded = true;
        });

        $this->artisan('scout:flush', ['model' => SearchableModel::class])
            ->expectsOutputToContain('have been flushed')
            ->assertSuccessful();

        $this->assertTrue($guarded);
    }
}
