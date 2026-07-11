<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Hypervel\Testing\PHPUnit\AfterEachTestSubscriber;
use Hypervel\Tests\TestCase;
use Override;
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
}
