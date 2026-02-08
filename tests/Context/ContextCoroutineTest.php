<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;
use Swow\Psr7\Message\ResponsePlusInterface;
use Swow\Psr7\Message\ServerRequestPlusInterface;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class ContextCoroutineTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testCopy()
    {
        Context::set('test.store.id', $uid = uniqid());
        $id = Coroutine::id();
        parallel([
            function () use ($id, $uid) {
                Context::copy($id, ['test.store.id']);
                $this->assertSame($uid, Context::get('test.store.id'));
            },
        ]);
    }

    public function testCopyAfterSet()
    {
        Context::set('test.store.id', $uid = uniqid());
        $id = Coroutine::id();
        parallel([
            function () use ($id, $uid) {
                Context::set('test.store.name', 'Hyperf');
                Context::copy($id, ['test.store.id']);
                $this->assertSame($uid, Context::get('test.store.id'));

                // Context::copy will delete origin values.
                $this->assertNull(Context::get('test.store.name'));
            },
        ]);
    }

    public function testContextChangeAfterCopy()
    {
        $obj = new stdClass();
        $obj->id = $uid = uniqid();

        Context::set('test.store.id', $obj);
        Context::set('test.store.useless.id', 1);
        $id = Coroutine::id();
        $tid = uniqid();
        parallel([
            function () use ($id, $uid, $tid) {
                Context::copy($id, ['test.store.id']);
                $obj = Context::get('test.store.id');
                $this->assertSame($uid, $obj->id);
                $obj->id = $tid;
                $this->assertFalse(Context::has('test.store.useless.id'));
            },
        ]);

        $this->assertSame($tid, Context::get('test.store.id')->id);
    }

    public function testContextFromNull()
    {
        $res = Context::get('id', $default = 'Hello World!', -1);
        $this->assertSame($default, $res);

        $res = Context::get('id', null, -1);
        $this->assertSame(null, $res);

        $this->assertFalse(Context::has('id', -1));

        Context::copy(-1);

        parallel([
            function () {
                Context::set('id', $id = uniqid());
                Context::copy(-1, ['id']);
                $this->assertSame($id, Context::get('id'));
            },
        ]);
    }

    public function testResponseContextWithCoroutineId()
    {
        $response = m::mock(ResponsePlusInterface::class);
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
        $request = m::mock(ServerRequestPlusInterface::class);
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
        Context::override('override.id.coroutine_id', fn () => $value);
        (new Waiter())->wait(function () use ($id, $value) {
            Context::override(
                'override.id.coroutine_id',
                function ($v) use ($value) {
                    $this->assertSame($v, $value);
                    return '123';
                },
                $id
            );
        });

        $this->assertSame('123', Context::get('override.id.coroutine_id'));
    }

    public function testContextGetOrSetWithCoroutineId()
    {
        $id = Coroutine::id();
        $value = uniqid();
        Context::getOrSet('get_or_set.id.coroutine_id', fn () => $value);
        (new Waiter())->wait(function () use ($id, $value) {
            $res = Context::getOrSet('get_or_set.id.coroutine_id', fn () => '123', $id);
            $this->assertSame($res, $value);
        });
    }
}
