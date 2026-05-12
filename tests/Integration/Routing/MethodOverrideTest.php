<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Support\Facades\Route;

class MethodOverrideTest extends RoutingTestCase
{
    public function testPostBodyMethodOverrideDispatchesToPutRoute()
    {
        Route::put('/widgets/{widget}', fn (string $widget) => "updated-{$widget}");

        $this->post('/widgets/42', ['_method' => 'PUT'])
            ->assertOk()
            ->assertContent('updated-42');
    }

    public function testQueryStringMethodOverrideDispatchesToPutRoute()
    {
        Route::put('/widgets/{widget}', fn (string $widget) => "updated-{$widget}");

        $this->post('/widgets/42?_method=PUT')
            ->assertOk()
            ->assertContent('updated-42');
    }
}
