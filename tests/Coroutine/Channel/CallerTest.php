<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine\Channel;

use Hypervel\Coroutine\Channel\Caller;
use Hypervel\Coroutine\Exception\WaitTimeoutException;
use Hypervel\Tests\Coroutine\CoroutineTestCase;
use stdClass;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class CallerTest extends CoroutineTestCase
{
    public function testCallerWithNull(): void
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

    public function testCaller(): void
    {
        $obj = new stdClass();
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

    public function testCallerPopTimeout(): void
    {
        $obj = new stdClass();
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
}
