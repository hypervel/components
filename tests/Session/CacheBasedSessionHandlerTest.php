<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Session\CacheBasedSessionHandler;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class CacheBasedSessionHandlerTest extends TestCase
{
    protected CacheContract $cacheMock;

    protected CacheBasedSessionHandler $sessionHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = m::mock(CacheContract::class);
        $this->sessionHandler = new CacheBasedSessionHandler($this->cacheMock, 10);
    }

    public function testOpen()
    {
        $result = $this->sessionHandler->open('path', 'session_name');
        $this->assertTrue($result);
    }

    public function testClose()
    {
        $result = $this->sessionHandler->close();
        $this->assertTrue($result);
    }

    public function testReadReturnsDataFromCache()
    {
        $this->cacheMock->shouldReceive('get')->once()->with('session_id', '')->andReturn('session_data');

        $data = $this->sessionHandler->read('session_id');
        $this->assertSame('session_data', $data);
    }

    public function testReadReturnsEmptyStringIfNoData()
    {
        $this->cacheMock->shouldReceive('get')->once()->with('some_id', '')->andReturn('');

        $data = $this->sessionHandler->read('some_id');
        $this->assertSame('', $data);
    }

    public function testWriteStoresDataInCache()
    {
        $this->cacheMock->shouldReceive('put')->once()->with('session_id', 'session_data', 600)
            ->andReturn(true);

        $result = $this->sessionHandler->write('session_id', 'session_data');

        $this->assertTrue($result);
    }

    public function testDestroyRemovesDataFromCache()
    {
        $this->cacheMock->shouldReceive('forget')->once()->with('session_id')->andReturn(true);

        $result = $this->sessionHandler->destroy('session_id');

        $this->assertTrue($result);
    }

    public function testGcReturnsZero()
    {
        $result = $this->sessionHandler->gc(120);

        $this->assertSame(0, $result);
    }

    public function testGetCacheReturnsCacheInstance()
    {
        $this->assertSame($this->cacheMock, $this->sessionHandler->getCache());
    }
}
