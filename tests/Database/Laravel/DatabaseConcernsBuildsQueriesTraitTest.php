<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Concerns\BuildsQueries;
use Hypervel\Tests\TestCase;

class DatabaseConcernsBuildsQueriesTraitTest extends TestCase
{
    public function testTapCallbackInstance()
    {
        $mock = new class
        {
            use BuildsQueries;
        };

        $mock->tap(function ($builder) use ($mock) {
            $this->assertEquals($mock, $builder);
        });
    }
}
