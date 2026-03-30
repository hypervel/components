<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function Hypervel\Coroutine\run;

/**
 * @internal
 * @coversNothing
 */
class ContextTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testSetMany()
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

    /**
     * @covers ::copyFromNonCoroutine
     */
    public function testCopyFromNonCoroutineCopiesAllKeys()
    {
        CoroutineContext::set('foo', 'foo');
        CoroutineContext::set('bar', 'bar');

        run(function () {
            Coroutine::create(function () {
                CoroutineContext::copyFromNonCoroutine();
                $this->assertSame('foo', CoroutineContext::get('foo'));
                $this->assertSame('bar', CoroutineContext::get('bar'));
            });
        });
    }

    /**
     * @covers ::copyFromNonCoroutine
     */
    public function testCopyFromNonCoroutinePreservesExistingCoroutineValues()
    {
        CoroutineContext::set('from_non_co', 'copied');

        run(function () {
            Coroutine::create(function () {
                // Set a value in the coroutine before copying
                CoroutineContext::set('existing', 'should_survive');

                CoroutineContext::copyFromNonCoroutine();

                // Copied value is present
                $this->assertSame('copied', CoroutineContext::get('from_non_co'));
                // Pre-existing coroutine value is preserved
                $this->assertSame('should_survive', CoroutineContext::get('existing'));
            });
        });
    }

    /**
     * @covers ::copyFromNonCoroutine
     */
    public function testCopyFromNonCoroutineWithSelectiveKeysPreservesExisting()
    {
        CoroutineContext::set('wanted', 'yes');
        CoroutineContext::set('unwanted', 'no');

        run(function () {
            Coroutine::create(function () {
                CoroutineContext::set('existing', 'kept');

                CoroutineContext::copyFromNonCoroutine(['wanted']);

                $this->assertSame('yes', CoroutineContext::get('wanted'));
                $this->assertNull(CoroutineContext::get('unwanted'));
                $this->assertSame('kept', CoroutineContext::get('existing'));
            });
        });
    }

    /**
     * @covers ::flush
     */
    public function testFlush()
    {
        CoroutineContext::set('key1', 'value1');
        CoroutineContext::set('key2', 'value2');

        $this->assertTrue(CoroutineContext::has('key1'));
        $this->assertTrue(CoroutineContext::has('key2'));

        CoroutineContext::flush();

        $this->assertFalse(CoroutineContext::has('key1'));
        $this->assertFalse(CoroutineContext::has('key2'));
    }

    public function testOverride()
    {
        CoroutineContext::set('override.id', 1);

        $this->assertSame(2, CoroutineContext::override('override.id', function ($id) {
            return $id + 1;
        }));

        $this->assertSame(2, CoroutineContext::get('override.id'));
    }

    public function testGetOrSet()
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

    public function testContextForget()
    {
        CoroutineContext::set($id = uniqid(), $value = uniqid());

        $this->assertSame($value, CoroutineContext::get($id));
        CoroutineContext::forget($id);
        $this->assertNull(CoroutineContext::get($id));
    }

    public function testRequestContext()
    {
        $request = m::mock(Request::class);
        RequestContext::set($request);
        $this->assertSame($request, RequestContext::get());

        CoroutineContext::set(Request::class, $req = m::mock(Request::class));
        $this->assertNotSame($request, RequestContext::get());
        $this->assertSame($req, RequestContext::get());
        $this->assertSame($req, CoroutineContext::get(Request::class));
    }

    public function testResponseContext()
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
