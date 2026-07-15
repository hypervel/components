<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use ArrayObject;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Exceptions\CoroutineDestroyedException;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Event;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function Hypervel\Coroutine\run;

class ContextTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testSetMany(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        CoroutineContext::setMany($values);

        foreach ($values as $key => $expectedValue) {
            $this->assertTrue(CoroutineContext::has($key));
            $this->assertEquals($expectedValue, CoroutineContext::get($key));
        }
    }

    public function testCopyFromNonCoroutineCopiesAllKeys(): void
    {
        CoroutineContext::set('foo', 'foo');
        CoroutineContext::set('bar', 'bar');
        $copied = [];

        run(static function () use (&$copied): void {
            Coroutine::create(static function () use (&$copied): void {
                CoroutineContext::copyFromNonCoroutine();
                $copied = [
                    'foo' => CoroutineContext::get('foo'),
                    'bar' => CoroutineContext::get('bar'),
                ];
            });
        });

        $this->assertSame(['foo' => 'foo', 'bar' => 'bar'], $copied);
    }

    public function testCopyFromNonCoroutinePreservesExistingCoroutineValues(): void
    {
        CoroutineContext::set('from_non_co', 'copied');
        $copied = [];

        run(static function () use (&$copied): void {
            Coroutine::create(static function () use (&$copied): void {
                CoroutineContext::set('existing', 'should_survive');
                CoroutineContext::copyFromNonCoroutine();
                $copied = [
                    'from_non_co' => CoroutineContext::get('from_non_co'),
                    'existing' => CoroutineContext::get('existing'),
                ];
            });
        });

        $this->assertSame([
            'from_non_co' => 'copied',
            'existing' => 'should_survive',
        ], $copied);
    }

    public function testCopyFromNonCoroutineWithSelectiveKeysPreservesExisting(): void
    {
        CoroutineContext::set('wanted', 'yes');
        CoroutineContext::set('unwanted', 'no');
        $copied = [];

        run(static function () use (&$copied): void {
            Coroutine::create(static function () use (&$copied): void {
                CoroutineContext::set('existing', 'kept');
                CoroutineContext::copyFromNonCoroutine(['wanted']);
                $copied = [
                    'wanted' => CoroutineContext::get('wanted'),
                    'unwanted' => CoroutineContext::get('unwanted'),
                    'existing' => CoroutineContext::get('existing'),
                ];
            });
        });

        $this->assertSame([
            'wanted' => 'yes',
            'unwanted' => null,
            'existing' => 'kept',
        ], $copied);
    }

    public function testFlush(): void
    {
        CoroutineContext::set('key1', 'value1');
        CoroutineContext::set('key2', 'value2');

        $this->assertTrue(CoroutineContext::has('key1'));
        $this->assertTrue(CoroutineContext::has('key2'));

        CoroutineContext::flush();

        $this->assertFalse(CoroutineContext::has('key1'));
        $this->assertFalse(CoroutineContext::has('key2'));
    }

    public function testExplicitCoroutineIdTargetsLiveContextFromOutsideCoroutine(): void
    {
        CoroutineContext::set('shared', 'fallback');

        $coroutineId = Coroutine::create(static function (): void {
            CoroutineContext::set('shared', 'child');
            CoroutineContext::set('child-only', 'value');
            EngineCoroutine::yield();
        });

        try {
            $this->assertTrue(CoroutineContext::has('shared', $coroutineId));
            $this->assertSame('child', CoroutineContext::get('shared', null, $coroutineId));

            $container = CoroutineContext::getContainer($coroutineId);
            $this->assertInstanceOf(ArrayObject::class, $container);
            $this->assertSame('value', $container['child-only']);

            CoroutineContext::set('shared', 'updated', $coroutineId);
            $this->assertSame('updated', CoroutineContext::get('shared', null, $coroutineId));
            $this->assertSame('fallback', CoroutineContext::getFromNonCoroutine('shared'));

            CoroutineContext::forget('shared', $coroutineId);
            $this->assertFalse(CoroutineContext::has('shared', $coroutineId));
            $this->assertSame('fallback', CoroutineContext::getFromNonCoroutine('shared'));

            CoroutineContext::flush($coroutineId);
            $this->assertSame([], $container->getArrayCopy());
            $this->assertSame('fallback', CoroutineContext::getFromNonCoroutine('shared'));
        } finally {
            EngineCoroutine::resumeById($coroutineId);
            // Drain explicitly so Swoole does not fall back to its deprecated shutdown wait.
            Event::wait();
        }
    }

    public function testCurrentCoroutineMutationNeverClearsFallbackStorage(): void
    {
        CoroutineContext::set('forgotten', 'fallback-forgotten');
        CoroutineContext::set('flushed', 'fallback-flushed');
        $observed = [];

        run(static function () use (&$observed): void {
            CoroutineContext::set('forgotten', 'coroutine-forgotten');
            CoroutineContext::set('flushed', 'coroutine-flushed');

            CoroutineContext::forget('forgotten');
            $observed['forgotten'] = CoroutineContext::has('forgotten');
            $observed['fallback-forgotten'] = CoroutineContext::getFromNonCoroutine('forgotten');

            CoroutineContext::flush();
            $observed['flushed'] = CoroutineContext::has('flushed');
            $observed['fallback-flushed'] = CoroutineContext::getFromNonCoroutine('flushed');
        });

        $this->assertSame([
            'forgotten' => false,
            'fallback-forgotten' => 'fallback-forgotten',
            'flushed' => false,
            'fallback-flushed' => 'fallback-flushed',
        ], $observed);
    }

    public function testExplicitDestroyedCoroutineIdNeverUsesFallbackStorage(): void
    {
        $coroutineId = Coroutine::create(static function (): void {
            // The coroutine exits before its ID is used as an explicit target.
        });
        // Drain explicitly so Swoole does not fall back to its deprecated shutdown wait.
        Event::wait();

        CoroutineContext::set('shared', 'fallback');

        $this->assertSame('default', CoroutineContext::get('shared', 'default', $coroutineId));
        $this->assertFalse(CoroutineContext::has('shared', $coroutineId));
        $this->assertNull(CoroutineContext::getContainer($coroutineId));

        CoroutineContext::forget('shared', $coroutineId);
        CoroutineContext::flush($coroutineId);
        CoroutineContext::flush(-1);

        $this->assertSame('fallback', CoroutineContext::getFromNonCoroutine('shared'));

        try {
            CoroutineContext::set('shared', 'target', $coroutineId);
            $this->fail('Expected an explicit write to a destroyed coroutine to fail.');
        } catch (CoroutineDestroyedException $exception) {
            $this->assertSame("Coroutine #{$coroutineId} has been destroyed.", $exception->getMessage());
        }

        $this->assertSame('fallback', CoroutineContext::getFromNonCoroutine('shared'));
    }

    public function testOverride(): void
    {
        CoroutineContext::set('override.id', 1);

        $this->assertSame(2, CoroutineContext::override('override.id', function ($id) {
            return $id + 1;
        }));

        $this->assertSame(2, CoroutineContext::get('override.id'));
    }

    public function testGetOrSet(): void
    {
        CoroutineContext::set('test.store.id', null);
        $this->assertSame(1, CoroutineContext::getOrSet('test.store.id', function () {
            return 1;
        }));
        $this->assertSame(1, CoroutineContext::getOrSet('test.store.id', function () {
            return 2;
        }));

        CoroutineContext::set('test.store.id', null);
        $this->assertSame(1, CoroutineContext::getOrSet('test.store.id', 1));
    }

    public function testContextForget(): void
    {
        CoroutineContext::set($id = uniqid(), $value = uniqid());

        $this->assertSame($value, CoroutineContext::get($id));
        CoroutineContext::forget($id);
        $this->assertNull(CoroutineContext::get($id));
    }

    public function testRequestContext(): void
    {
        $request = m::mock(Request::class);
        RequestContext::set($request);
        $this->assertSame($request, RequestContext::get());

        CoroutineContext::set(Request::class, $req = m::mock(Request::class));
        $this->assertNotSame($request, RequestContext::get());
        $this->assertSame($req, RequestContext::get());
        $this->assertSame($req, CoroutineContext::get(Request::class));
    }

    public function testResponseContext(): void
    {
        $response = m::mock(Response::class);
        ResponseContext::set($response);
        $this->assertSame($response, ResponseContext::get());

        CoroutineContext::set(SymfonyResponse::class, $res = m::mock(Response::class));
        $this->assertNotSame($response, ResponseContext::get());
        $this->assertSame($res, ResponseContext::get());
        $this->assertSame($res, CoroutineContext::get(SymfonyResponse::class));
    }
}
