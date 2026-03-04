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
    protected function setUp(): void
    {
        parent::setUp();

        Context::destroyAll();
    }

    protected function tearDown(): void
    {
        Context::destroyAll();
        parent::tearDown();
    }

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
    public function testCopyFromNonCoroutineWithSpecificKeys()
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
     * @covers ::destroyAll
     */
    public function testDestroyAll()
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));

        Context::destroyAll();

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

    public function testContextDestroy()
    {
        Context::set($id = uniqid(), $value = uniqid());

        $this->assertSame($value, Context::get($id));
        Context::destroy($id);
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
}
