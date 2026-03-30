<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class ContextCoroutineTest extends TestCase
{
    public function testCopy()
    {
        CoroutineContext::set('test.store.id', $uid = uniqid());
        $id = Coroutine::id();
        parallel([
            function () use ($id, $uid) {
                CoroutineContext::copyFrom($id, ['test.store.id']);
                $this->assertSame($uid, CoroutineContext::get('test.store.id'));
            },
        ]);
    }

    public function testCopyAfterSet()
    {
        CoroutineContext::set('test.store.id', $uid = uniqid());
        $id = Coroutine::id();
        parallel([
            function () use ($id, $uid) {
                CoroutineContext::set('test.store.name', 'Hypervel');
                CoroutineContext::copyFrom($id, ['test.store.id']);
                $this->assertSame($uid, CoroutineContext::get('test.store.id'));

                // CoroutineContext::copyFrom() merges — existing values are preserved.
                $this->assertSame('Hypervel', CoroutineContext::get('test.store.name'));
            },
        ]);
    }

    public function testContextChangeAfterCopy()
    {
        $obj = new stdClass();
        $obj->id = $uid = uniqid();

        CoroutineContext::set('test.store.id', $obj);
        CoroutineContext::set('test.store.useless.id', 1);
        $id = Coroutine::id();
        $tid = uniqid();
        parallel([
            function () use ($id, $uid, $tid) {
                CoroutineContext::copyFrom($id, ['test.store.id']);
                $obj = CoroutineContext::get('test.store.id');
                $this->assertSame($uid, $obj->id);
                $obj->id = $tid;
                $this->assertFalse(CoroutineContext::has('test.store.useless.id'));
            },
        ]);

        $this->assertSame($tid, CoroutineContext::get('test.store.id')->id);
    }

    public function testContextFromNull()
    {
        $res = CoroutineContext::get('id', $default = 'Hello World!', -1);
        $this->assertSame($default, $res);

        $res = CoroutineContext::get('id', null, -1);
        $this->assertSame(null, $res);

        $this->assertFalse(CoroutineContext::has('id', -1));

        CoroutineContext::copyFrom(-1);

        parallel([
            function () {
                CoroutineContext::set('id', $id = uniqid());
                CoroutineContext::copyFrom(-1, ['id']);
                $this->assertSame($id, CoroutineContext::get('id'));
            },
        ]);
    }

    public function testResponseContextWithCoroutineId()
    {
        $response = m::mock(Response::class);
        $chan = new Channel(1);
        $close = new Channel(1);
        go(function () use ($chan, $response, $close) {
            ResponseContext::set($response);
            $this->assertSame($response, ResponseContext::get());
            $chan->push(Coroutine::id());
            $close->pop(1);
        });

        $id = $chan->pop(5);
        $this->assertSame($response, ResponseContext::get($id));
        $close->push(true);
    }

    public function testRequestContextWithCoroutineId()
    {
        $request = m::mock(Request::class);
        RequestContext::set($request);
        $id = Coroutine::id();
        (new Waiter())->wait(function () use ($id, $request) {
            $this->assertSame($request, RequestContext::get($id));
        });
    }

    public function testContextOverrideWithCoroutineId()
    {
        $id = Coroutine::id();
        $value = uniqid();
        CoroutineContext::override('override.id.coroutine_id', fn () => $value);
        (new Waiter())->wait(function () use ($id, $value) {
            CoroutineContext::override(
                'override.id.coroutine_id',
                function ($v) use ($value) {
                    $this->assertSame($v, $value);
                    return '123';
                },
                $id
            );
        });

        $this->assertSame('123', CoroutineContext::get('override.id.coroutine_id'));
    }

    public function testContextGetOrSetWithCoroutineId()
    {
        $id = Coroutine::id();
        $value = uniqid();
        CoroutineContext::getOrSet('get_or_set.id.coroutine_id', fn () => $value);
        (new Waiter())->wait(function () use ($id, $value) {
            $res = CoroutineContext::getOrSet('get_or_set.id.coroutine_id', fn () => '123', $id);
            $this->assertSame($res, $value);
        });
    }
}
