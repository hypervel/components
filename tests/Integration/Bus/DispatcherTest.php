<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Bus;

use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Log;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class DispatcherTest extends TestCase
{
    // End the request coroutine before Mockery verifies the deferred exception report.
    protected bool $runTestsInCoroutine = false;

    /**
     * Define the application's routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('/test', function () {
            dispatch(fn () => throw new RuntimeException('thrown after response'))
                ->afterResponse();

            return response('response before dispatch');
        });
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testDispatchingAfterResponseDoesNotInterruptResponseOutputEvenWithExceptions(bool $deferred): void
    {
        if (! $deferred) {
            Bus::withoutDispatchingAfterResponses();
        }

        Log::expects('error')
            ->with('thrown after response', m::type('array'));

        $this->runInCoroutine(function (): void {
            $this->get('/test')
                ->assertSee('response before dispatch')
                ->assertStatus(200);
        });
    }
}
