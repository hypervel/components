<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Closure;
use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Hypervel\Tests\TestCase;
use Override;
use RuntimeException;

class AfterEachTestCleanupTest extends TestCase
{
    private const string CALLBACK_NAME = self::class;

    #[Override]
    protected function tearDown(): void
    {
        AfterEachTestCleanupStateStub::forgetCallbacks();

        parent::tearDown();
    }

    public function testFlushUsingRegistersCallbacksByName(): void
    {
        $calls = [];

        AfterEachTestCleanupStateStub::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });

        AfterEachTestCleanupStateStub::runCallbacks();

        $this->assertSame(['package'], $calls);
    }

    public function testCallbacksRunInRegistrationOrder(): void
    {
        $calls = [];

        AfterEachTestCleanupStateStub::flushUsing('vendor/first', function () use (&$calls): void {
            $calls[] = 'first';
        });
        AfterEachTestCleanupStateStub::flushUsing('vendor/second', function () use (&$calls): void {
            $calls[] = 'second';
        });

        AfterEachTestCleanupStateStub::runCallbacks();

        $this->assertSame(['first', 'second'], $calls);
    }

    public function testCallbacksPersistAcrossRuns(): void
    {
        $calls = [];

        AfterEachTestCleanupStateStub::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });

        AfterEachTestCleanupStateStub::runCallbacks();
        AfterEachTestCleanupStateStub::runCallbacks();

        $this->assertSame(['package', 'package'], $calls);
    }

    public function testRegisteringTheSameNameReplacesTheCallback(): void
    {
        $calls = [];

        AfterEachTestCleanupStateStub::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'first';
        });
        AfterEachTestCleanupStateStub::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'second';
        });

        AfterEachTestCleanupStateStub::runCallbacks();

        $this->assertSame(['second'], $calls);
    }

    public function testRootCallbackRegisteredAfterPackageCallbackRunsAfterPackageCallback(): void
    {
        $calls = [];

        AfterEachTestCleanupStateStub::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });
        AfterEachTestCleanupStateStub::flushUsing('app', function () use (&$calls): void {
            $calls[] = 'app';
        });

        AfterEachTestCleanupStateStub::runCallbacks();

        $this->assertSame(['package', 'app'], $calls);
    }

    public function testForgetCallbacksClearsCallbacks(): void
    {
        $calls = [];

        AfterEachTestCleanupStateStub::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });

        AfterEachTestCleanupStateStub::forgetCallbacks();
        AfterEachTestCleanupStateStub::runCallbacks();

        $this->assertSame([], $calls);
    }

    public function testForgetRemovesOnlyTheNamedCallback(): void
    {
        $calls = [];

        AfterEachTestCleanupStateStub::flushUsing('vendor/first', function () use (&$calls): void {
            $calls[] = 'first';
        });
        AfterEachTestCleanupStateStub::flushUsing('vendor/second', function () use (&$calls): void {
            $calls[] = 'second';
        });

        AfterEachTestCleanupStateStub::forget('vendor/first');
        AfterEachTestCleanupStateStub::runCallbacks();

        $this->assertSame(['second'], $calls);
    }

    public function testIsolatedRegistryResetDoesNotForgetWorkerCallbacks(): void
    {
        $calls = [];

        AfterEachTestCleanup::flushUsing(self::CALLBACK_NAME, function () use (&$calls): void {
            $calls[] = 'worker';
        });

        try {
            AfterEachTestCleanupStateStub::flushUsing('temporary', static function (): void {
            });
            AfterEachTestCleanupStateStub::forgetCallbacks();
            AfterEachTestCleanup::runCallbacks();

            $this->assertSame(['worker'], $calls);
        } finally {
            AfterEachTestCleanup::forget(self::CALLBACK_NAME);
        }
    }

    public function testRunCallbacksContinuesAfterExceptionAndRethrowsFirstException(): void
    {
        $calls = [];
        $firstException = new RuntimeException('first');

        AfterEachTestCleanupStateStub::flushUsing('vendor/first', function () use (&$calls, $firstException): void {
            $calls[] = 'first';

            throw $firstException;
        });
        AfterEachTestCleanupStateStub::flushUsing('vendor/second', function () use (&$calls): void {
            $calls[] = 'second';
        });

        try {
            AfterEachTestCleanupStateStub::runCallbacks();

            $this->fail('Expected callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstException, $exception);
        }

        $this->assertSame(['first', 'second'], $calls);
    }
}

class AfterEachTestCleanupStateStub extends AfterEachTestCleanup
{
    /**
     * The isolated callback registry for this test class.
     *
     * @var array<string, Closure(): void>
     */
    protected static array $callbacks = [];
}
