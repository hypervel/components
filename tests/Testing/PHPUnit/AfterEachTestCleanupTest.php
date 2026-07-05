<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Hypervel\Tests\TestCase;
use Override;
use RuntimeException;

class AfterEachTestCleanupTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        AfterEachTestCleanup::forgetCallbacks();

        parent::tearDown();
    }

    public function testFlushUsingRegistersCallbacksByName(): void
    {
        $calls = [];

        AfterEachTestCleanup::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });

        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['package'], $calls);
    }

    public function testCallbacksRunInRegistrationOrder(): void
    {
        $calls = [];

        AfterEachTestCleanup::flushUsing('vendor/first', function () use (&$calls): void {
            $calls[] = 'first';
        });
        AfterEachTestCleanup::flushUsing('vendor/second', function () use (&$calls): void {
            $calls[] = 'second';
        });

        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['first', 'second'], $calls);
    }

    public function testCallbacksPersistAcrossRuns(): void
    {
        $calls = [];

        AfterEachTestCleanup::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });

        AfterEachTestCleanup::runCallbacks();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['package', 'package'], $calls);
    }

    public function testRegisteringTheSameNameReplacesTheCallback(): void
    {
        $calls = [];

        AfterEachTestCleanup::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'first';
        });
        AfterEachTestCleanup::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'second';
        });

        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['second'], $calls);
    }

    public function testRootCallbackRegisteredAfterPackageCallbackRunsAfterPackageCallback(): void
    {
        $calls = [];

        AfterEachTestCleanup::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });
        AfterEachTestCleanup::flushUsing('app', function () use (&$calls): void {
            $calls[] = 'app';
        });

        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['package', 'app'], $calls);
    }

    public function testForgetCallbacksClearsCallbacks(): void
    {
        $calls = [];

        AfterEachTestCleanup::flushUsing('vendor/package', function () use (&$calls): void {
            $calls[] = 'package';
        });

        AfterEachTestCleanup::forgetCallbacks();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame([], $calls);
    }

    public function testRunCallbacksContinuesAfterExceptionAndRethrowsFirstException(): void
    {
        $calls = [];
        $firstException = new RuntimeException('first');

        AfterEachTestCleanup::flushUsing('vendor/first', function () use (&$calls, $firstException): void {
            $calls[] = 'first';

            throw $firstException;
        });
        AfterEachTestCleanup::flushUsing('vendor/second', function () use (&$calls): void {
            $calls[] = 'second';
        });

        try {
            AfterEachTestCleanup::runCallbacks();

            $this->fail('Expected callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstException, $exception);
        }

        $this->assertSame(['first', 'second'], $calls);
    }
}
