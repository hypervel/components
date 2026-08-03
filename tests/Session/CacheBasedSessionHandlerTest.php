<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Session\CacheBasedSessionHandler;
use Hypervel\Session\Store;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

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

    public function testOpen(): void
    {
        $result = $this->sessionHandler->open('path', 'session_name');
        $this->assertTrue($result);
    }

    public function testClose(): void
    {
        $result = $this->sessionHandler->close();
        $this->assertTrue($result);
    }

    public function testReadReturnsDataFromCache(): void
    {
        $this->cacheMock->shouldReceive('get')->once()->with('session_id', '')->andReturn('session_data');

        $data = $this->sessionHandler->read('session_id');
        $this->assertSame('session_data', $data);
    }

    public function testReadReturnsEmptyStringIfNoData(): void
    {
        $this->cacheMock->shouldReceive('get')->once()->with('some_id', '')->andReturn('');

        $data = $this->sessionHandler->read('some_id');
        $this->assertSame('', $data);
    }

    public function testWriteStoresDataInCache(): void
    {
        $this->cacheMock->shouldReceive('put')->once()->with('session_id', 'session_data', 600)
            ->andReturn(true);

        $result = $this->sessionHandler->write('session_id', 'session_data');

        $this->assertTrue($result);
    }

    public function testDestroyRemovesDataFromCache(): void
    {
        $this->cacheMock->shouldReceive('forget')->once()->with('session_id')->andReturn(true);

        $result = $this->sessionHandler->destroy('session_id');

        $this->assertTrue($result);
    }

    public function testGcReturnsZero(): void
    {
        $result = $this->sessionHandler->gc(120);

        $this->assertSame(0, $result);
    }

    public function testGetCacheReturnsCacheInstance(): void
    {
        $this->assertSame($this->cacheMock, $this->sessionHandler->getCache());
    }

    public function testFalseCacheWriteLeavesLiveSessionStateForRetry(): void
    {
        $this->cacheMock->shouldReceive('get')->once()->andReturn(serialize([]));

        $payloads = [];
        $this->cacheMock->shouldReceive('put')
            ->twice()
            ->withArgs(function (string $sessionId, string $data, int $seconds) use (&$payloads): bool {
                $payloads[] = unserialize($data);

                return $sessionId === str_repeat('a', 40) && $seconds === 600;
            })
            ->andReturn(false, true);

        $session = new Store('name', $this->sessionHandler, str_repeat('a', 40));
        $session->start();
        $session->flash('status', 'saved');

        try {
            $session->save();

            $this->fail('Expected the failed cache write to reject the session save.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the session data.', $exception->getMessage());
        }

        $this->assertTrue($session->isStarted());
        $this->assertSame(['status'], $session->get('_flash.new'));

        $session->save();

        $this->assertFalse($session->isStarted());
        $this->assertSame(['status'], $session->get('_flash.old'));
        $this->assertSame($payloads[0], $payloads[1]);
    }
}
