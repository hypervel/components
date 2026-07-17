<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine\Channel;

use Hypervel\Coroutine\Channel\Caller;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Tests\TestCase;
use RuntimeException;
use stdClass;

use function Hypervel\Coroutine\go;

class CallerTest extends TestCase
{
    public function testCallerWithNull()
    {
        $caller = new Caller(static function () {
            return null;
        });

        $id = $caller->call(static function ($instance) {
            return 1;
        });

        $this->assertSame(1, $id);

        $id = $caller->call(static function ($instance) {
            return 2;
        });

        $this->assertSame(2, $id);
    }

    public function testCaller()
    {
        $obj = new stdClass;
        $obj->id = uniqid();
        $caller = new Caller(static function () use ($obj) {
            return $obj;
        });

        $id = $caller->call(static function ($instance) {
            return $instance->id;
        });

        $this->assertSame($obj->id, $id);

        $caller->call(function ($instance) use ($obj) {
            $this->assertSame($instance, $obj);
        });
    }

    public function testCallerPopTimeout()
    {
        $obj = new stdClass;
        $obj->id = uniqid();
        $caller = new Caller(static function () use ($obj) {
            return $obj;
        }, 0.001);

        go(static function () use ($caller) {
            $caller->call(static function ($instance) {
                usleep(10 * 1000);
            });
        });

        $this->expectException(WaitTimeoutException::class);

        $caller->call(static function ($instance) {
            return 1;
        });
    }

    public function testFailedReinitializationPreservesTheCurrentInstance(): void
    {
        $instance = new stdClass;
        $attempts = 0;
        $caller = new Caller(static function () use ($instance, &$attempts): stdClass {
            if (++$attempts > 1) {
                throw new RuntimeException('Unable to create the replacement instance.');
            }

            return $instance;
        });

        try {
            $caller->initInstance();
            $this->fail('Expected replacement creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to create the replacement instance.', $exception->getMessage());
        }

        $this->assertSame(
            $instance,
            $caller->call(static fn (stdClass $current): stdClass => $current),
        );
    }
}
