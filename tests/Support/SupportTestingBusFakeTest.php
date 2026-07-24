<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Support\Testing\Fakes\BatchRepositoryFake;
use Hypervel\Support\Testing\Fakes\BusFake;
use Hypervel\Support\Testing\Fakes\PendingBatchFake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;

class SupportTestingBusFakeTest extends TestCase
{
    protected BusFake $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new BusFake(m::mock(QueueingDispatcher::class));
    }

    public function testItUsesCustomBusRepository(): void
    {
        $busRepository = new BatchRepositoryFake;

        $fake = new BusFake(m::mock(QueueingDispatcher::class), [], $busRepository);

        $this->assertNull($fake->findBatch('non-existent-batch'));

        $batch = $fake->batch([])->dispatch();

        $this->assertSame($batch, $fake->findBatch($batch->id));
        $this->assertSame($batch, $busRepository->find($batch->id));
    }

    public function testAssertDispatched(): void
    {
        try {
            $this->fake->assertDispatched(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was not dispatched.', $e->getMessage());
        }

        $this->fake->dispatch(new BusJobStub);

        $this->fake->assertDispatched(BusJobStub::class);
    }

    public function testAssertDispatchedWithClosure(): void
    {
        $this->fake->dispatch(new BusJobStub);

        $this->fake->assertDispatched(function (BusJobStub $job) {
            return true;
        });
    }

    public function testAssertDispatchedAfterResponse(): void
    {
        try {
            $this->fake->assertDispatchedAfterResponse(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was not dispatched after sending the response.', $e->getMessage());
        }

        $this->fake->dispatchAfterResponse(new BusJobStub);

        $this->fake->assertDispatchedAfterResponse(BusJobStub::class);
    }

    public function testAssertDispatchedAfterResponseClosure(): void
    {
        try {
            $this->fake->assertDispatchedAfterResponse(function (BusJobStub $job) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was not dispatched after sending the response.', $e->getMessage());
        }
    }

    public function testAssertDispatchedSync(): void
    {
        try {
            $this->fake->assertDispatchedSync(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was not dispatched synchronously.', $e->getMessage());
        }

        $this->fake->dispatch(new BusJobStub);

        try {
            $this->fake->assertDispatchedSync(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was not dispatched synchronously.', $e->getMessage());
        }

        $this->fake->dispatchSync(new BusJobStub);

        $this->fake->assertDispatchedSync(BusJobStub::class);
    }

    public function testAssertDispatchedSyncClosure(): void
    {
        try {
            $this->fake->assertDispatchedSync(function (BusJobStub $job) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was not dispatched synchronously.', $e->getMessage());
        }
    }

    public function testAssertDispatchedNow(): void
    {
        $this->fake->dispatchNow(new BusJobStub);

        $this->fake->assertDispatched(BusJobStub::class);
    }

    #[DataProvider('fakeReturnMethods')]
    public function testFakeDispatchMethodsReturnNull(string $dispatchMethod): void
    {
        $this->assertNull($this->fake->{$dispatchMethod}(new BusJobStub));
    }

    public static function fakeReturnMethods(): array
    {
        return [
            ['dispatch'],
            ['dispatchSync'],
            ['dispatchNow'],
            ['dispatchToQueue'],
        ];
    }

    public function testAssertDispatchedWithCallbackInt(): void
    {
        $this->fake->dispatch(new BusJobStub);
        $this->fake->dispatchNow(new BusJobStub);

        try {
            $this->fake->assertDispatched(BusJobStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertDispatched(BusJobStub::class, 2);
    }

    public function testAssertDispatchedAfterResponseWithCallbackInt(): void
    {
        $this->fake->dispatchAfterResponse(new BusJobStub);
        $this->fake->dispatchAfterResponse(new BusJobStub);

        try {
            $this->fake->assertDispatchedAfterResponse(BusJobStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertDispatchedAfterResponse(BusJobStub::class, 2);
    }

    public function testAssertDispatchedSyncWithCallbackInt(): void
    {
        $this->fake->dispatchSync(new BusJobStub);
        $this->fake->dispatchSync(new BusJobStub);

        try {
            $this->fake->assertDispatchedSync(BusJobStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was synchronously pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertDispatchedSync(BusJobStub::class, 2);
    }

    public function testAssertDispatchedWithCallbackFunction(): void
    {
        $this->fake->dispatch(new OtherBusJobStub);
        $this->fake->dispatchNow(new OtherBusJobStub(1));

        try {
            $this->fake->assertDispatched(OtherBusJobStub::class, function ($job) {
                return $job->id === 0;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\OtherBusJobStub] job was not dispatched.', $e->getMessage());
        }

        $this->fake->assertDispatched(OtherBusJobStub::class, function ($job) {
            return $job->id === null;
        });

        $this->fake->assertDispatched(OtherBusJobStub::class, function ($job) {
            return $job->id === 1;
        });
    }

    public function testAssertDispatchedAfterResponseWithCallbackFunction(): void
    {
        $this->fake->dispatchAfterResponse(new OtherBusJobStub);
        $this->fake->dispatchAfterResponse(new OtherBusJobStub(1));

        try {
            $this->fake->assertDispatchedAfterResponse(OtherBusJobStub::class, function ($job) {
                return $job->id === 0;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\OtherBusJobStub] job was not dispatched after sending the response.', $e->getMessage());
        }

        $this->fake->assertDispatchedAfterResponse(OtherBusJobStub::class, function ($job) {
            return $job->id === null;
        });

        $this->fake->assertDispatchedAfterResponse(OtherBusJobStub::class, function ($job) {
            return $job->id === 1;
        });
    }

    public function testAssertDispatchedAfterResponseTimesWithCallbackFunction(): void
    {
        $this->fake->dispatchAfterResponse(new OtherBusJobStub(0));
        $this->fake->dispatchAfterResponse(new OtherBusJobStub(1));
        $this->fake->dispatchAfterResponse(new OtherBusJobStub(1));

        try {
            $this->fake->assertDispatchedAfterResponseTimes(function (OtherBusJobStub $job) {
                return $job->id === 0;
            }, 2);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\OtherBusJobStub] job was pushed 1 time instead of 2 times.', $e->getMessage());
        }

        $this->fake->assertDispatchedAfterResponseTimes(function (OtherBusJobStub $job) {
            return $job->id === 0;
        });

        $this->fake->assertDispatchedAfterResponseTimes(function (OtherBusJobStub $job) {
            return $job->id === 1;
        }, 2);
    }

    public function testAssertDispatchedSyncWithCallbackFunction(): void
    {
        $this->fake->dispatchSync(new OtherBusJobStub);
        $this->fake->dispatchSync(new OtherBusJobStub(1));

        try {
            $this->fake->assertDispatchedSync(OtherBusJobStub::class, function ($job) {
                return $job->id === 0;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\OtherBusJobStub] job was not dispatched synchronously.', $e->getMessage());
        }

        $this->fake->assertDispatchedSync(OtherBusJobStub::class, function ($job) {
            return $job->id === null;
        });

        $this->fake->assertDispatchedSync(OtherBusJobStub::class, function ($job) {
            return $job->id === 1;
        });
    }

    public function testAssertDispatchedOnce(): void
    {
        $this->fake->dispatch(new BusJobStub);
        $this->fake->dispatchNow(new BusJobStub);

        try {
            $this->fake->assertDispatchedOnce(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertDispatchedTimes(BusJobStub::class, 2);
    }

    public function testAssertDispatchedTimes(): void
    {
        $this->fake->dispatch(new BusJobStub);
        $this->fake->dispatchNow(new BusJobStub);

        try {
            $this->fake->assertDispatchedTimes(BusJobStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertDispatchedTimes(BusJobStub::class, 2);
    }

    public function testAssertDispatchedTimesWithCallbackFunction(): void
    {
        $this->fake->dispatch(new OtherBusJobStub(0));
        $this->fake->dispatchNow(new OtherBusJobStub(1));
        $this->fake->dispatchAfterResponse(new OtherBusJobStub(1));

        try {
            $this->fake->assertDispatchedTimes(function (OtherBusJobStub $job) {
                return $job->id === 0;
            }, 2);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\OtherBusJobStub] job was pushed 1 time instead of 2 times.', $e->getMessage());
        }

        $this->fake->assertDispatchedTimes(function (OtherBusJobStub $job) {
            return $job->id === 0;
        });

        $this->fake->assertDispatchedTimes(function (OtherBusJobStub $job) {
            return $job->id === 1;
        }, 2);
    }

    public function testAssertDispatchedAfterResponseTimes(): void
    {
        $this->fake->dispatchAfterResponse(new BusJobStub);
        $this->fake->dispatchAfterResponse(new BusJobStub);

        try {
            $this->fake->assertDispatchedAfterResponseTimes(BusJobStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertDispatchedAfterResponseTimes(BusJobStub::class, 2);
    }

    public function testAssertDispatchedSyncTimes(): void
    {
        $this->fake->dispatchSync(new BusJobStub);
        $this->fake->dispatchSync(new BusJobStub);

        try {
            $this->fake->assertDispatchedSyncTimes(BusJobStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\BusJobStub] job was synchronously pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertDispatchedSyncTimes(BusJobStub::class, 2);
    }

    public function testAssertDispatchedSyncTimesWithCallbackFunction(): void
    {
        $this->fake->dispatchSync(new OtherBusJobStub(0));
        $this->fake->dispatchSync(new OtherBusJobStub(1));
        $this->fake->dispatchSync(new OtherBusJobStub(1));

        try {
            $this->fake->assertDispatchedSyncTimes(function (OtherBusJobStub $job) {
                return $job->id === 0;
            }, 2);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\OtherBusJobStub] job was synchronously pushed 1 time instead of 2 times.', $e->getMessage());
        }

        $this->fake->assertDispatchedSyncTimes(function (OtherBusJobStub $job) {
            return $job->id === 0;
        });

        $this->fake->assertDispatchedSyncTimes(function (OtherBusJobStub $job) {
            return $job->id === 1;
        }, 2);
    }

    #[DataProvider('countAssertionMethods')]
    public function testCountAssertionsPluralizeFailureMessages(
        string $dispatchMethod,
        string $assertionMethod,
        string $action
    ): void {
        $this->fake->{$dispatchMethod}(new BusJobStub);

        try {
            $this->fake->{$assertionMethod}(BusJobStub::class, 2);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString(
                'The expected [' . BusJobStub::class . "] {$action} 1 time instead of 2 times.",
                $exception->getMessage()
            );
        }
    }

    public static function countAssertionMethods(): array
    {
        return [
            ['dispatch', 'assertDispatchedTimes', 'job was pushed'],
            ['dispatchSync', 'assertDispatchedSyncTimes', 'job was synchronously pushed'],
            ['dispatchAfterResponse', 'assertDispatchedAfterResponseTimes', 'job was pushed'],
        ];
    }

    public function testAssertNotDispatched(): void
    {
        $this->fake->assertNotDispatched(BusJobStub::class);

        $this->fake->dispatch(new BusJobStub);
        $this->fake->dispatchNow(new BusJobStub);

        try {
            $this->fake->assertNotDispatched(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\BusJobStub] job was dispatched.', $e->getMessage());
        }
    }

    public function testAssertNotDispatchedWithClosure(): void
    {
        $this->fake->dispatch(new BusJobStub);
        $this->fake->dispatchNow(new BusJobStub);

        try {
            $this->fake->assertNotDispatched(function (BusJobStub $job) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\BusJobStub] job was dispatched.', $e->getMessage());
        }
    }

    public function testAssertNotDispatchedAfterResponse(): void
    {
        $this->fake->assertNotDispatchedAfterResponse(BusJobStub::class);

        $this->fake->dispatchAfterResponse(new BusJobStub);

        try {
            $this->fake->assertNotDispatchedAfterResponse(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\BusJobStub] job was dispatched after sending the response.', $e->getMessage());
        }
    }

    public function testAssertNotDispatchedAfterResponseClosure(): void
    {
        $this->fake->dispatchAfterResponse(new BusJobStub);

        try {
            $this->fake->assertNotDispatchedAfterResponse(function (BusJobStub $job) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\BusJobStub] job was dispatched after sending the response.', $e->getMessage());
        }
    }

    public function testAssertNotDispatchedSync(): void
    {
        $this->fake->assertNotDispatchedSync(BusJobStub::class);

        $this->fake->dispatchSync(new BusJobStub);

        try {
            $this->fake->assertNotDispatchedSync(BusJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\BusJobStub] job was dispatched synchronously.', $e->getMessage());
        }
    }

    public function testAssertNotDispatchedSyncClosure(): void
    {
        $this->fake->dispatchSync(new BusJobStub);

        try {
            $this->fake->assertNotDispatchedSync(function (BusJobStub $job) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\BusJobStub] job was dispatched synchronously.', $e->getMessage());
        }
    }

    public function testAssertNothingDispatched(): void
    {
        $this->fake->assertNothingDispatched();

        $this->fake->dispatch(new BusJobStub);

        try {
            $this->fake->assertNothingDispatched();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The following jobs were dispatched unexpectedly:', $e->getMessage());
            $this->assertStringContainsString(BusJobStub::class, $e->getMessage());
        }
    }

    public function testAssertNothingDispatchedWithSyncDispatch(): void
    {
        $this->fake->assertNothingDispatched();

        $this->fake->dispatchSync(new BusJobStub);

        try {
            $this->fake->assertNothingDispatched();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The following jobs were dispatched unexpectedly:', $e->getMessage());
            $this->assertStringContainsString(BusJobStub::class, $e->getMessage());
        }
    }

    public function testAssertNothingDispatchedWithAfterResponseDispatch(): void
    {
        $this->fake->assertNothingDispatched();

        $this->fake->dispatchAfterResponse(new BusJobStub);

        try {
            $this->fake->assertNothingDispatched();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The following jobs were dispatched unexpectedly:', $e->getMessage());
            $this->assertStringContainsString(BusJobStub::class, $e->getMessage());
        }
    }

    public function testAssertChained(): void
    {
        Container::setInstance($container = new Container);

        $container->instance(Dispatcher::class, $this->fake);

        $this->fake->chain([
            new ChainedJobStub,
        ])->dispatch();

        $this->fake->assertChained([
            ChainedJobStub::class,
        ]);

        $this->fake->chain([
            new ChainedJobStub,
            new OtherBusJobStub,
        ])->dispatch();

        $this->fake->assertChained([
            ChainedJobStub::class,
            OtherBusJobStub::class,
        ]);

        $this->fake->chain([
            new ChainedJobStub,
            $this->fake->batch([
                new OtherBusJobStub,
                new OtherBusJobStub,
            ]),
            new ChainedJobStub,
        ])->dispatch();

        $this->fake->assertChained([
            ChainedJobStub::class,
            $this->fake->chainedBatch(function ($pendingBatch) {
                return $pendingBatch->jobs->count() === 2;
            }),
            ChainedJobStub::class,
        ]);

        $this->fake->assertChained([
            new ChainedJobStub,
            $this->fake->chainedBatch(function ($pendingBatch) {
                return $pendingBatch->jobs->count() === 2;
            }),
            new ChainedJobStub,
        ]);

        $this->fake->chain([
            $this->fake->batch([
                new OtherBusJobStub,
                new OtherBusJobStub,
            ]),
            new ChainedJobStub,
            new ChainedJobStub,
        ])->dispatch();

        $this->fake->assertChained([
            $this->fake->chainedBatch(function ($pendingBatch) {
                return $pendingBatch->jobs->count() === 2;
            }),
            ChainedJobStub::class,
            ChainedJobStub::class,
        ]);

        $this->fake->chain([
            new ChainedJobStub(123),
            new ChainedJobStub(456),
        ])->dispatch();

        $this->fake->assertChained([
            fn (ChainedJobStub $job) => $job->id === 123,
            fn (ChainedJobStub $job) => $job->id === 456,
        ]);

        Container::setInstance(null);
    }

    public function testAssertNothingChained(): void
    {
        $this->fake->assertNothingChained();
    }

    public function testAssertNothingChainedFails(): void
    {
        $this->fake->chain([new ChainedJobStub])->dispatch();

        try {
            $this->fake->assertNothingChained();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The following jobs were dispatched unexpectedly:', $e->getMessage());
            $this->assertStringContainsString(ChainedJobStub::class, $e->getMessage());
        }
    }

    public function testAssertDispatchedWithIgnoreClass(): void
    {
        $dispatcher = m::mock(QueueingDispatcher::class);

        $job = new BusJobStub;
        $dispatcher->shouldReceive('dispatch')->once()->with($job);
        $dispatcher->shouldReceive('dispatchNow')->once()->with($job, null);

        $otherJob = new OtherBusJobStub;
        $dispatcher->shouldReceive('dispatch')->never()->with($otherJob);
        $dispatcher->shouldReceive('dispatchNow')->never()->with($otherJob, null);

        $fake = new BusFake($dispatcher, OtherBusJobStub::class);

        $fake->dispatch($job);
        $fake->dispatchNow($job);

        $fake->dispatch($otherJob);
        $fake->dispatchNow($otherJob);

        $fake->assertNotDispatched(BusJobStub::class);
        $fake->assertDispatchedTimes(OtherBusJobStub::class, 2);
    }

    public function testDispatchedFakingOnlyGivenJobs(): void
    {
        $dispatcher = m::mock(QueueingDispatcher::class);

        $job = new BusJobStub;
        $dispatcher->shouldReceive('dispatch')->never()->with($job);
        $dispatcher->shouldReceive('dispatchNow')->never()->with($job, null);

        $otherJob = new OtherBusJobStub;
        $dispatcher->shouldReceive('dispatch')->once()->with($otherJob);
        $dispatcher->shouldReceive('dispatchNow')->once()->with($otherJob, null);

        $thirdJob = new ThirdJob;
        $dispatcher->shouldReceive('dispatch')->never()->with($thirdJob);
        $dispatcher->shouldReceive('dispatchNow')->never()->with($thirdJob, null);

        $fake = (new BusFake($dispatcher))->except(OtherBusJobStub::class);

        $fake->dispatch($job);
        $fake->dispatchNow($job);

        $fake->dispatch($otherJob);
        $fake->dispatchNow($otherJob);

        $fake->dispatch($thirdJob);
        $fake->dispatchNow($thirdJob);

        $fake->assertNotDispatched(OtherBusJobStub::class);
        $fake->assertDispatchedTimes(BusJobStub::class, 2);
        $fake->assertDispatchedTimes(ThirdJob::class, 2);
    }

    public function testBulkRecordsEachJob(): void
    {
        $this->fake->bulk([new BusJobStub, new BusJobStub]);

        $this->fake->assertDispatchedTimes(BusJobStub::class, 2);
    }

    public function testAssertDispatchedWithIgnoreCallback(): void
    {
        $dispatcher = m::mock(QueueingDispatcher::class);

        $job = new BusJobStub;
        $dispatcher->shouldReceive('dispatch')->once()->with($job);
        $dispatcher->shouldReceive('dispatchNow')->once()->with($job, null);

        $otherJob = new OtherBusJobStub;
        $dispatcher->shouldReceive('dispatch')->once()->with($otherJob);
        $dispatcher->shouldReceive('dispatchNow')->once()->with($otherJob, null);

        $anotherJob = new OtherBusJobStub(1);
        $dispatcher->shouldReceive('dispatch')->never()->with($anotherJob);
        $dispatcher->shouldReceive('dispatchNow')->never()->with($anotherJob, null);

        $fake = new BusFake($dispatcher, [
            function ($command) {
                return $command instanceof OtherBusJobStub && $command->id === 1;
            },
        ]);

        $fake->dispatch($job);
        $fake->dispatchNow($job);

        $fake->dispatch($otherJob);
        $fake->dispatchNow($otherJob);

        $fake->dispatch($anotherJob);
        $fake->dispatchNow($anotherJob);

        $fake->assertNotDispatched(BusJobStub::class);
        $fake->assertDispatchedTimes(OtherBusJobStub::class, 2);
        $fake->assertNotDispatched(OtherBusJobStub::class, function ($job) {
            return $job->id === null;
        });
        $fake->assertDispatched(OtherBusJobStub::class, function ($job) {
            return $job->id === 1;
        });
    }

    public function testAssertNothingBatched(): void
    {
        $this->fake->assertNothingBatched();

        $job = new BusJobStub;

        $this->fake->batch([$job])->dispatch();

        try {
            $this->fake->assertNothingBatched();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString("The following batched jobs were dispatched unexpectedly:\n\n- " . get_class($job), $e->getMessage());
        }
    }

    public function testAssertNothingPlacedPasses(): void
    {
        $this->fake->assertNothingPlaced();
    }

    public function testAssertNothingPlacedWhenJobBatched(): void
    {
        $this->fake->batch([new BusJobStub])->dispatch();

        $this->expectException(ExpectationFailedException::class);

        $this->fake->assertNothingPlaced();
    }

    public function testAssertNothingPlacedWhenJobDispatched(): void
    {
        $this->fake->dispatch(new BusJobStub);

        $this->expectException(ExpectationFailedException::class);

        $this->fake->assertNothingPlaced();
    }

    public function testAssertNothingPlacedWhenJobChained(): void
    {
        $this->fake->chain([new ChainedJobStub])->dispatch();

        $this->expectException(ExpectationFailedException::class);

        $this->fake->assertNothingPlaced();
    }

    public function testAssertNothingPlacedWhenJobDispatchedNow(): void
    {
        $this->fake->dispatchNow(new BusJobStub);

        $this->expectException(ExpectationFailedException::class);

        $this->fake->assertNothingPlaced();
    }

    public function testFindBatch(): void
    {
        $this->assertNull($this->fake->findBatch('non-existent-batch'));

        $batch = $this->fake->batch([])->dispatch();

        $this->assertSame($batch, $this->fake->findBatch($batch->id));
    }

    public function testBatchesCanBeCancelled(): void
    {
        $batch = $this->fake->batch([])->dispatch();

        $this->assertFalse($batch->cancelled());

        $batch->cancel();

        $this->assertTrue($batch->cancelled());
    }

    public function testDispatchFakeBatch(): void
    {
        $this->fake->assertNothingBatched();

        $batch = $this->fake->dispatchFakeBatch('my fake job batch');

        $this->fake->assertBatchCount(1);
        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertSame('my fake job batch', $batch->name);
        $this->assertSame(0, $batch->totalJobs);

        $batch = $this->fake->dispatchFakeBatch();

        $this->fake->assertBatchCount(2);
        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertSame('', $batch->name);
        $this->assertSame(0, $batch->totalJobs);
    }

    public function testIncrementFailedJobsInFakeBatch(): void
    {
        $this->fake->assertNothingBatched();
        $batch = $this->fake->dispatchFakeBatch('my fake job batch');

        $this->fake->assertBatchCount(1);
        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertSame('my fake job batch', $batch->name);
        $this->assertSame(0, $batch->totalJobs);

        $batch->incrementFailedJobs($batch->id);

        $this->assertSame(0, $batch->failedJobs);
        $this->assertSame(0, $batch->pendingJobs);
    }

    public function testDecrementPendingJobsInFakeBatch(): void
    {
        $this->fake->assertNothingBatched();
        $batch = $this->fake->dispatchFakeBatch('my fake job batch');

        $this->fake->assertBatchCount(1);
        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertSame('my fake job batch', $batch->name);
        $this->assertSame(0, $batch->totalJobs);

        $batch->decrementPendingJobs($batch->id);

        $this->assertSame(0, $batch->failedJobs);
        $this->assertSame(0, $batch->pendingJobs);
    }

    #[DataProvider('serializeAndRestoreCommandMethodsDataProvider')]
    public function testCanSerializeAndRestoreCommands(string $commandFunctionName, string $assertionFunctionName): void
    {
        $serializingBusFake = (clone $this->fake)->serializeAndRestore();

        // without setting the serialization, the job should return the value passed in
        $this->fake->{$commandFunctionName}(new BusFakeJobWithSerialization('hello'));
        $this->fake->{$assertionFunctionName}(BusFakeJobWithSerialization::class, fn ($command) => $command->value === 'hello');

        // when enabling the serializeAndRestore property, job has value modified
        $serializingBusFake->{$commandFunctionName}(new BusFakeJobWithSerialization('hello'));
        $serializingBusFake->{$assertionFunctionName}(
            BusFakeJobWithSerialization::class,
            fn ($command) => $command->value === 'hello-serialized-unserialized'
        );
    }

    public static function serializeAndRestoreCommandMethodsDataProvider(): array
    {
        return [
            'dispatch' => ['dispatch', 'assertDispatched'],
            'dispatchSync' => ['dispatchSync', 'assertDispatchedSync'],
            'dispatchNow' => ['dispatchNow', 'assertDispatched'],
            'dispatchAfterResponse' => ['dispatchAfterResponse', 'assertDispatchedAfterResponse'],
        ];
    }

    public function testCanSerializeAndRestoreCommandsInBatch(): void
    {
        $serializingBusFake = (clone $this->fake)->serializeAndRestore();

        // without setting the serialization, the batch should return the value passed in
        $this->fake->batch([
            new BusFakeJobWithSerialization('hello'),
        ])->dispatch();
        $this->fake->assertBatched(function (PendingBatchFake $batchedCollection): bool {
            return $batchedCollection->jobs->count() === 1 && $batchedCollection->jobs->first()->value === 'hello';
        });

        // when enabling the serializeAndRestore property, each job in the batch will be serialized/restored
        $pendingBatch = $serializingBusFake->batch([
            new BusFakeJobWithSerialization('hello'),
        ]);
        $pendingBatch->add(new BusFakeJobWithSerialization('added'));
        $pendingBatch->dispatch();

        $serializingBusFake->assertBatched(function (PendingBatchFake $batchedCollection): bool {
            return $batchedCollection->jobs->count() === 2
                && $batchedCollection->jobs[0]->value === 'hello-serialized-unserialized'
                && $batchedCollection->jobs[1]->value === 'added-serialized-unserialized';
        });
    }

    public function testDispatchAfterResponseWithHandler(): void
    {
        $job = new BusJobStub;
        $handler = function () {
            return 'handled';
        };

        $this->fake->dispatchAfterResponse($job, $handler);

        $this->fake->assertDispatchedAfterResponse(BusJobStub::class);
    }

    public function testCanAssertJobsOnPendingBatchFake(): void
    {
        $this->fake->batch([
            new BusFakeJobWithSerialization('foo'),
            new BusFakeJobWithSerialization('bar'),
            new BusFakeJobWithSerialization('baz'),
        ])->dispatch();

        $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
            return $batchedCollection->hasJobs([
                new BusFakeJobWithSerialization('foo'),
                new BusFakeJobWithSerialization('bar'),
                new BusFakeJobWithSerialization('baz'),
            ]);
        });

        $this->fake->assertBatched([
            new BusFakeJobWithSerialization('foo'),
            new BusFakeJobWithSerialization('bar'),
            new BusFakeJobWithSerialization('baz'),
        ]);

        try {
            $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
                return $batchedCollection->hasJobs([
                    new BusFakeJobWithSerialization('baz'),
                    new BusFakeJobWithSerialization('foo'),
                    new BusFakeJobWithSerialization('bar'),
                ]);
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected batch was not dispatched.', $e->getMessage());
        }

        try {
            $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
                return $batchedCollection->hasJobs([
                    new BusFakeJobWithSerialization('foo'),
                    new BusFakeJobWithSerialization('baaar'),
                    new BusFakeJobWithSerialization('baz'),
                ]);
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected batch was not dispatched.', $e->getMessage());
        }

        try {
            $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
                return $batchedCollection->hasJobs([
                    new BusFakeJobWithSerialization('foo'),
                    new BusFakeJobWithSerialization('baz'),
                ]);
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected batch was not dispatched.', $e->getMessage());
        }

        try {
            $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
                return $batchedCollection->hasJobs([
                    new BusFakeJobWithSerialization('foo'),
                    new BusFakeJobWithSerialization('bar'),
                    new BusFakeJobWithSerialization('baz'),
                    new BusFakeJobWithSerialization('qux'),
                ]);
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected batch was not dispatched.', $e->getMessage());
        }
    }

    public function testCanAssertJobsOnPendingBatchFakeWithClosures(): void
    {
        $this->fake->batch([
            new BusFakeJobWithSerialization('foo'),
            new BusFakeJobWithSerialization('bar'),
            new BusFakeJobWithSerialization('baz'),
        ])->dispatch();

        $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
            return $batchedCollection->hasJobs([
                fn (BusFakeJobWithSerialization $job) => $job->value === 'foo',
                fn (BusFakeJobWithSerialization $job) => $job->value === 'bar',
                fn (BusFakeJobWithSerialization $job) => $job->value === 'baz',
            ]);
        });

        $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
            return $batchedCollection->hasJobs([
                fn (BusFakeJobWithSerialization $job) => $job->value === 'foo',
                BusFakeJobWithSerialization::class,
                new BusFakeJobWithSerialization('baz'),
            ]);
        });

        try {
            $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
                return $batchedCollection->hasJobs([
                    fn (BusFakeJobWithSerialization $job) => $job->value === 'foo',
                    fn (BusFakeJobWithSerialization $job) => $job->value === 'wrong',
                    fn (BusFakeJobWithSerialization $job) => $job->value === 'baz',
                ]);
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected batch was not dispatched.', $e->getMessage());
        }

        try {
            $this->fake->assertBatched(function (PendingBatchFake $batchedCollection) {
                return $batchedCollection->hasJobs([
                    fn (BusFakeJobWithSerialization $job) => $job->value === 'foo',
                    fn (BusJobStub $job) => true,
                    fn (BusFakeJobWithSerialization $job) => $job->value === 'baz',
                ]);
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected batch was not dispatched.', $e->getMessage());
        }
    }
}

class BusJobStub
{
}

class ChainedJobStub
{
    use Queueable;

    public ?int $id;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
    }
}

class OtherBusJobStub
{
    public ?int $id;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
    }
}

class ThirdJob
{
}

class BusFakeJobWithSerialization
{
    use Batchable;
    use Queueable;

    public function __construct(public string $value)
    {
    }

    public function __serialize(): array
    {
        return ['value' => $this->value . '-serialized'];
    }

    public function __unserialize(array $data): void
    {
        $this->value = $data['value'] . '-unserialized';
    }
}
