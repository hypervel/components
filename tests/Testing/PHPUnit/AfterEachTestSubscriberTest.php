<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Database\Eloquent\Factories\Factory as EloquentFactory;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Http\Client\Factory as HttpFactory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\Response as HttpClientResponse;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Http\Response as HttpResponse;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Testing\Fakes\NotificationFake;
use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Hypervel\Testing\PHPUnit\AfterEachTestSubscriber;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\Exception\InvalidCountException;
use Override;
use ReflectionClass;
use RuntimeException;

class AfterEachTestSubscriberTest extends TestCase
{
    private const string CALLBACK_NAME = self::class;

    #[Override]
    protected function tearDown(): void
    {
        AfterEachTestCleanup::forget(self::CALLBACK_NAME);

        parent::tearDown();
    }

    public function testFrameworkCleanupFlushesEveryMacroableRegistry(): void
    {
        $classes = [
            EloquentFactory::class,
            HttpFactory::class,
            PendingRequest::class,
            HttpClientResponse::class,
            JsonResponse::class,
            RedirectResponse::class,
            Request::class,
            JsonResource::class,
            JsonApiResource::class,
            HttpResponse::class,
            UploadedFile::class,
            NotificationFake::class,
        ];
        $macro = 'testingStaticStateProbe';

        foreach ($classes as $class) {
            $class::macro($macro, static fn (): string => 'ok');
            $this->assertTrue($class::hasMacro($macro));
        }

        $subscriber = new class extends AfterEachTestSubscriber {
            public function flushFrameworkStateForTest(): void
            {
                $this->flushFrameworkState();
            }
        };

        try {
            $subscriber->flushFrameworkStateForTest();

            foreach ($classes as $class) {
                $this->assertFalse($class::hasMacro($macro));
            }
        } finally {
            foreach ($classes as $class) {
                $class::flushMacros();
            }
        }
    }

    public function testFrameworkCleanupFlushesInheritedRequestStaticState(): void
    {
        $request = new Request;
        $request->setFormat('testing', 'application/x-testing');
        Request::enableHttpMethodParameterOverride();
        Request::setAllowedHttpMethodOverride(['PATCH']);
        Request::setFactory(static fn (): Request => new Request(attributes: ['from_factory' => true]));

        $this->assertSame(['application/x-testing'], Request::getMimeTypes('testing'));
        $this->assertTrue(Request::getHttpMethodParameterOverride());
        $this->assertSame(['PATCH'], Request::getAllowedHttpMethodOverride());
        $this->assertTrue(Request::create('/')->attributes->get('from_factory'));

        $subscriber = new class extends AfterEachTestSubscriber {
            public function flushFrameworkStateForTest(): void
            {
                $this->flushFrameworkState();
            }
        };

        try {
            $subscriber->flushFrameworkStateForTest();

            $this->assertSame([], Request::getMimeTypes('testing'));
            $this->assertFalse(Request::getHttpMethodParameterOverride());
            $this->assertNull(Request::getAllowedHttpMethodOverride());
            $this->assertNull(Request::create('/')->attributes->get('from_factory'));
        } finally {
            Request::flushState();
        }
    }

    public function testFlushStateAfterTestRunsCustomCallbacksBeforeFrameworkCleanup(): void
    {
        $subscriber = new class extends AfterEachTestSubscriber {
            /**
             * The observed cleanup order.
             *
             * @var array<int, string>
             */
            public array $order = [];

            /**
             * Flush framework-owned static state.
             */
            protected function flushFrameworkState(): void
            {
                $this->order[] = 'framework';
            }
        };

        AfterEachTestCleanup::flushUsing(self::CALLBACK_NAME, function () use ($subscriber): void {
            $subscriber->order[] = 'custom';
        });

        $subscriber->flushStateAfterTest();

        $this->assertSame(['custom', 'framework'], $subscriber->order);
    }

    public function testFlushStateAfterTestRunsFrameworkCleanupWhenCustomCallbackThrows(): void
    {
        $subscriber = new class extends AfterEachTestSubscriber {
            /**
             * The observed cleanup order.
             *
             * @var array<int, string>
             */
            public array $order = [];

            /**
             * Flush framework-owned static state.
             */
            protected function flushFrameworkState(): void
            {
                $this->order[] = 'framework';
            }
        };
        $expectedException = new RuntimeException('custom cleanup failed');

        AfterEachTestCleanup::flushUsing(self::CALLBACK_NAME, function () use ($subscriber, $expectedException): void {
            $subscriber->order[] = 'custom';

            throw $expectedException;
        });

        try {
            $subscriber->flushStateAfterTest();

            $this->fail('Expected cleanup exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedException, $exception);
        }

        $this->assertSame(['custom', 'framework'], $subscriber->order);
    }

    public function testFlushStateAfterTestRunsFrameworkCleanupWhenMockeryVerificationFails(): void
    {
        $subscriber = new class extends AfterEachTestSubscriber {
            public bool $frameworkStateFlushed = false;

            /**
             * Flush framework-owned static state.
             */
            protected function flushFrameworkState(): void
            {
                $this->frameworkStateFlushed = true;
            }
        };
        m::mock()->shouldReceive('expected')->once();

        try {
            $subscriber->flushStateAfterTest();
            $this->fail('Expected Mockery verification to fail.');
        } catch (InvalidCountException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($subscriber->frameworkStateFlushed);
    }

    public function testCustomCleanupFailureRemainsPrimaryWhenMockeryAlsoFails(): void
    {
        $subscriber = new class extends AfterEachTestSubscriber {
            public bool $frameworkStateFlushed = false;

            /**
             * Flush framework-owned static state.
             */
            protected function flushFrameworkState(): void
            {
                $this->frameworkStateFlushed = true;
            }
        };
        $expectedException = new RuntimeException('custom cleanup failed');
        AfterEachTestCleanup::flushUsing(self::CALLBACK_NAME, static function () use ($expectedException): never {
            throw $expectedException;
        });
        m::mock()->shouldReceive('expected')->once();

        try {
            $subscriber->flushStateAfterTest();
            $this->fail('Expected cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedException, $exception);
        }

        $this->assertTrue($subscriber->frameworkStateFlushed);
    }

    public function testEarlierCleanupFailureRemainsPrimaryWhenFrameworkCleanupAlsoFails(): void
    {
        $frameworkFailure = new RuntimeException('framework cleanup failed');
        $subscriber = new class($frameworkFailure) extends AfterEachTestSubscriber {
            public bool $frameworkStateFlushed = false;

            public function __construct(private RuntimeException $failure)
            {
            }

            /**
             * Flush framework-owned static state.
             */
            protected function flushFrameworkState(): void
            {
                $this->frameworkStateFlushed = true;

                throw $this->failure;
            }
        };
        $expectedException = new RuntimeException('custom cleanup failed');
        AfterEachTestCleanup::flushUsing(self::CALLBACK_NAME, static function () use ($expectedException): never {
            throw $expectedException;
        });

        try {
            $subscriber->flushStateAfterTest();
            $this->fail('Expected cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedException, $exception);
        }

        $this->assertTrue($subscriber->frameworkStateFlushed);
    }

    public function testDatabaseCleanupFailureDoesNotSkipFrameworkStateReset(): void
    {
        $expectedException = new RuntimeException('database cleanup failed');
        $connection = new class($expectedException) implements ConnectionInterface {
            public function __construct(private RuntimeException $exception)
            {
            }

            public function getConnection(): mixed
            {
                return null;
            }

            public function reconnect(): bool
            {
                return true;
            }

            public function check(): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function release(): void
            {
            }

            public function discard(): void
            {
                throw $this->exception;
            }
        };
        $property = (new ReflectionClass(DatabaseConnectionResolver::class))
            ->getProperty('pooledConnections');
        $property->setValue(null, ['testing' => $connection]);
        $subscriber = new class extends AfterEachTestSubscriber {
            public bool $frameworkStateFlushed = false;

            protected function flushFrameworkState(): void
            {
                $this->frameworkStateFlushed = true;
            }
        };

        try {
            $subscriber->flushStateAfterTest();
            $this->fail('Expected database cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedException, $exception);
        }

        $this->assertTrue($subscriber->frameworkStateFlushed);
    }

    public function testFirstPreparationTracksCleanupWithoutFlushing(): void
    {
        $subscriber = new AfterEachTestSubscriberStateStub;

        $subscriber->handlePreparationStarted();

        $this->assertTrue($subscriber->cleanupIsPending());
        $this->assertSame(0, $subscriber->cleanupCount);
    }

    public function testPreparedTestCleansOnceWithoutExecutionFinishedRetry(): void
    {
        $subscriber = new AfterEachTestSubscriberStateStub;

        $subscriber->handlePreparationStarted();
        $subscriber->finishTest();
        $subscriber->handleExecutionFinished();

        $this->assertFalse($subscriber->cleanupIsPending());
        $this->assertSame(1, $subscriber->cleanupCount);
    }

    public function testNextPreparationCleansUnpreparedTestAndTracksCurrentTest(): void
    {
        $subscriber = new AfterEachTestSubscriberStateStub;

        $subscriber->handlePreparationStarted();
        $subscriber->handlePreparationStarted();

        $this->assertTrue($subscriber->cleanupIsPending());
        $this->assertSame(1, $subscriber->cleanupCount);

        $subscriber->finishTest();

        $this->assertFalse($subscriber->cleanupIsPending());
        $this->assertSame(2, $subscriber->cleanupCount);
    }

    public function testExecutionFinishedCleansLastUnpreparedTest(): void
    {
        $subscriber = new AfterEachTestSubscriberStateStub;

        $subscriber->handlePreparationStarted();
        $subscriber->handleExecutionFinished();

        $this->assertFalse($subscriber->cleanupIsPending());
        $this->assertSame(1, $subscriber->cleanupCount);
    }

    public function testThrowingDeferredCleanupIsNotRetriedAndCurrentTestRemainsTracked(): void
    {
        $subscriber = new AfterEachTestSubscriberStateStub;
        $expectedException = new RuntimeException('cleanup failed');

        $subscriber->handlePreparationStarted();
        $subscriber->cleanupFailure = $expectedException;

        try {
            $subscriber->handlePreparationStarted();
            $this->fail('Expected deferred cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedException, $exception);
        }

        $this->assertTrue($subscriber->cleanupIsPending());
        $this->assertSame(1, $subscriber->cleanupCount);

        $subscriber->cleanupFailure = null;
        $subscriber->finishTest();
        $subscriber->handleExecutionFinished();

        $this->assertFalse($subscriber->cleanupIsPending());
        $this->assertSame(2, $subscriber->cleanupCount);
    }

    public function testThrowingFinishedCleanupIsNotRetried(): void
    {
        $subscriber = new AfterEachTestSubscriberStateStub;
        $expectedException = new RuntimeException('cleanup failed');

        $subscriber->handlePreparationStarted();
        $subscriber->cleanupFailure = $expectedException;

        try {
            $subscriber->finishTest();
            $this->fail('Expected finished cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedException, $exception);
        }

        $subscriber->cleanupFailure = null;
        $subscriber->handleExecutionFinished();

        $this->assertFalse($subscriber->cleanupIsPending());
        $this->assertSame(1, $subscriber->cleanupCount);
    }
}

class AfterEachTestSubscriberStateStub extends AfterEachTestSubscriber
{
    public int $cleanupCount = 0;

    public ?RuntimeException $cleanupFailure = null;

    /**
     * Flush test state or throw the configured failure.
     */
    #[Override]
    public function flushStateAfterTest(): void
    {
        ++$this->cleanupCount;

        if ($this->cleanupFailure !== null) {
            throw $this->cleanupFailure;
        }
    }

    /**
     * Finish a prepared test through the pending-cleanup path.
     */
    public function finishTest(): void
    {
        $this->flushPendingCleanup();
    }

    /**
     * Determine whether cleanup remains pending.
     */
    public function cleanupIsPending(): bool
    {
        return $this->cleanupPending;
    }
}
