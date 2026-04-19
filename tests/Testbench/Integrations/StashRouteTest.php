<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class StashRouteTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        $this->defineStashRoutes(function () {
            Route::get('stubs-controller', 'Workbench\App\Http\Controllers\ExampleController@index');
        });

        parent::setUp();
    }

    #[Test]
    public function itCanCacheRoute()
    {
        $this->get('stubs-controller')
            ->assertOk()
            ->assertSee('ExampleController@index');
    }
}
