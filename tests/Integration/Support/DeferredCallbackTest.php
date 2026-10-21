<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support;

use Hypervel\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Queue\Fixtures\TestSyncJob;

class DeferredCallbackTest extends TestCase
{
    #[WithConfig('queue.default', 'sync')]
    public function testDeferredCallbackIsNotDiscardedBySyncJob(): void
    {
        $executed = false;

        Route::get('/test', function () use (&$executed): void {
            defer(function () use (&$executed): void {
                $executed = true;
            });

            dispatch(new TestSyncJob);
        })->middleware(InvokeDeferredCallbacks::class);

        $this->get('/test');

        $this->assertTrue($executed);
    }

    public function testCallbacksDeferredWithinADeferredCallbackAreInvoked(): void
    {
        $result = [];

        Route::get('/test', function () use (&$result): void {
            defer(function () use (&$result): void {
                $result[] = 'first';

                defer(function () use (&$result): void {
                    $result[] = 'second';
                });
            });
        });

        $this->get('/test');

        $this->assertSame(['first', 'second'], $result);
    }
}
