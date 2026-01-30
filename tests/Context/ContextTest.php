<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\Context;
use Hypervel\Coroutine\Coroutine;
use PHPUnit\Framework\TestCase;

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

    public function testSetMany(): void
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
    public function testCopyFromNonCoroutineWithSpecificKeys(): void
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
    public function testDestroyAll(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));

        Context::destroyAll();

        $this->assertFalse(Context::has('key1'));
        $this->assertFalse(Context::has('key2'));
    }
}
