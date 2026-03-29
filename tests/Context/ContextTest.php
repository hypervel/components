<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\Context;
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

        Context::setMany($values);

        foreach ($values as $key => $expectedValue) {
            $this->assertTrue(Context::has($key));
            $this->assertEquals($expectedValue, Context::get($key));
        }
    }

    /**
     * @covers ::copyFromNonCoroutine
     */
    public function testCopyFromNonCoroutineCopiesAllKeys()
    {
        Context::set('foo', 'foo');
        Context::set('bar', 'bar');

        run(function () {
            Coroutine::create(function () {
                Context::copyFromNonCoroutine();
                $this->assertSame('foo', Context::get('foo'));
                $this->assertSame('bar', Context::get('bar'));
            });
        });
    }

    /**
     * @covers ::copyFromNonCoroutine
     */
    public function testCopyFromNonCoroutinePreservesExistingCoroutineValues()
    {
        Context::set('from_non_co', 'copied');

        run(function () {
            Coroutine::create(function () {
                // Set a value in the coroutine before copying
                Context::set('existing', 'should_survive');

                Context::copyFromNonCoroutine();

                // Copied value is present
                $this->assertSame('copied', Context::get('from_non_co'));
                // Pre-existing coroutine value is preserved
                $this->assertSame('should_survive', Context::get('existing'));
            });
        });
    }

    /**
     * @covers ::copyFromNonCoroutine
     */
    public function testCopyFromNonCoroutineWithSelectiveKeysPreservesExisting()
    {
        Context::set('wanted', 'yes');
        Context::set('unwanted', 'no');

        run(function () {
            Coroutine::create(function () {
                Context::set('existing', 'kept');

                Context::copyFromNonCoroutine(['wanted']);

                $this->assertSame('yes', Context::get('wanted'));
                $this->assertNull(Context::get('unwanted'));
                $this->assertSame('kept', Context::get('existing'));
            });
        });
    }

    /**
     * @covers ::flush
     */
    public function testFlush()
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));

        Context::flush();

        $this->assertFalse(Context::has('key1'));
        $this->assertFalse(Context::has('key2'));
    }

    public function testOverride()
    {
        Context::set('override.id', 1);

        $this->assertSame(2, Context::override('override.id', function ($id) {
            return $id + 1;
        }));

        $this->assertSame(2, Context::get('override.id'));
    }

    public function testGetOrSet()
    {
        Context::set('test.store.id', null);
        $this->assertSame(1, Context::getOrSet('test.store.id', function () {
            return 1;
        }));
        $this->assertSame(1, Context::getOrSet('test.store.id', function () {
            return 2;
        }));

        Context::set('test.store.id', null);
        $this->assertSame(1, Context::getOrSet('test.store.id', 1));
    }

    public function testContextForget()
    {
        Context::set($id = uniqid(), $value = uniqid());

        $this->assertSame($value, Context::get($id));
        Context::forget($id);
        $this->assertNull(Context::get($id));
    }

    public function testRequestContext()
    {
        $request = m::mock(Request::class);
        RequestContext::set($request);
        $this->assertSame($request, RequestContext::get());

        Context::set(Request::class, $req = m::mock(Request::class));
        $this->assertNotSame($request, RequestContext::get());
        $this->assertSame($req, RequestContext::get());
        $this->assertSame($req, Context::get(Request::class));
    }

    public function testResponseContext()
    {
        $response = m::mock(Response::class);
        ResponseContext::set($response);
        $this->assertSame($response, ResponseContext::get());

        Context::set(SymfonyResponse::class, $res = m::mock(Response::class));
        $this->assertNotSame($response, ResponseContext::get());
        $this->assertSame($res, ResponseContext::get());
        $this->assertSame($res, Context::get(SymfonyResponse::class));
    }

    // =========================================================================
    // context() helper
    // =========================================================================

    public function testContextHelperGetReturnsRawContextValue()
    {
        Context::set('key', 'val');

        $this->assertSame('val', context('key'));
    }

    public function testContextHelperSetWritesToRawContext()
    {
        context(['key' => 'val']);

        $this->assertSame('val', Context::get('key'));
    }
}
