<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
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
    #[Override]
    protected function tearDown(): void
    {
        AfterEachTestCleanup::forgetCallbacks();

        parent::tearDown();
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

        AfterEachTestCleanup::flushUsing('vendor/package', function () use ($subscriber): void {
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

        AfterEachTestCleanup::flushUsing('vendor/package', function () use ($subscriber, $expectedException): void {
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
        AfterEachTestCleanup::flushUsing('vendor/package', static function () use ($expectedException): never {
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
        AfterEachTestCleanup::flushUsing('vendor/package', static function () use ($expectedException): never {
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
}
