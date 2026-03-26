<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Session\FileSessionHandler;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Filesystem\join_paths;

/**
 * @internal
 * @coversNothing
 */
class FileSessionHandlerTest extends TestCase
{
    protected Filesystem $files;

    protected FileSessionHandler $sessionHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = m::mock(Filesystem::class);
        $this->sessionHandler = new FileSessionHandler($this->files, '/path/to/sessions', 30);
    }

    public function testOpen()
    {
        $this->assertTrue($this->sessionHandler->open('/path/to/sessions', 'session_name'));
    }

    public function testClose()
    {
        $this->assertTrue($this->sessionHandler->close());
    }

    public function testReadReturnsDataWhenFileExistsAndIsValid()
    {
        $sessionId = 'session_id';
        $path = '/path/to/sessions/' . $sessionId;
        Carbon::setTestNow(Carbon::parse('2025-02-02 01:30:00'));

        $this->files->shouldReceive('isFile')->with($path)->andReturn(true);

        $minutesAgo30 = Carbon::parse('2025-02-02 01:00:00')->getTimestamp();
        $this->files->shouldReceive('lastModified')->with($path)->andReturn($minutesAgo30);
        $this->files->shouldReceive('sharedGet')->with($path)->once()->andReturn('session_data');

        $result = $this->sessionHandler->read($sessionId);

        $this->assertSame('session_data', $result);
    }

    public function testReadReturnsEmptyWhenFileExistsButExpired()
    {
        $sessionId = 'session_id';
        $path = '/path/to/sessions/' . $sessionId;
        Carbon::setTestNow(Carbon::parse('2025-02-02 01:30:01'));

        $this->files->shouldReceive('isFile')->with($path)->andReturn(true);

        $minutesAgo30 = Carbon::parse('2025-02-02 01:00:00')->getTimestamp();
        $this->files->shouldReceive('lastModified')->with($path)->andReturn($minutesAgo30);
        $this->files->shouldReceive('sharedGet')->never();

        $result = $this->sessionHandler->read($sessionId);

        $this->assertSame('', $result);
    }

    public function testReadReturnsEmptyStringWhenFileDoesNotExist()
    {
        $sessionId = 'non_existing_session_id';
        $path = '/path/to/sessions/' . $sessionId;

        $this->files->shouldReceive('isFile')->with($path)->andReturn(false);

        $result = $this->sessionHandler->read($sessionId);

        $this->assertSame('', $result);
    }

    public function testWriteStoresData()
    {
        $sessionId = 'session_id';
        $data = 'session_data';

        $this->files->shouldReceive('put')->with('/path/to/sessions/' . $sessionId, $data, true)->once()->andReturn(null);

        $result = $this->sessionHandler->write($sessionId, $data);

        $this->assertTrue($result);
    }

    public function testDestroyDeletesSessionFile()
    {
        $sessionId = 'session_id';

        $this->files->shouldReceive('delete')->with('/path/to/sessions/' . $sessionId)->once()->andReturn(true);

        $result = $this->sessionHandler->destroy($sessionId);

        $this->assertTrue($result);
    }

    public function testGcDeletesOldSessionFiles()
    {
        $session = new FileSessionHandler($this->files, join_paths(__DIR__, 'tmp'), 30);

        $this->files->shouldReceive('delete')->with(join_paths(__DIR__, 'tmp', 'a2'))->once()->andReturn(false);
        $this->files->shouldReceive('delete')->with(join_paths(__DIR__, 'tmp', 'a3'))->once()->andReturn(true);

        mkdir(__DIR__ . '/tmp');
        touch(__DIR__ . '/tmp/a1', time() - 3); // last modified: 3 sec ago
        touch(__DIR__ . '/tmp/a2', time() - 5); // last modified: 5 sec ago
        touch(__DIR__ . '/tmp/a3', time() - 7); // last modified: 7 sec ago

        $count = $session->gc(5);

        $this->assertSame(2, $count);

        unlink(__DIR__ . '/tmp/a1');
        unlink(__DIR__ . '/tmp/a2');
        unlink(__DIR__ . '/tmp/a3');

        rmdir(__DIR__ . '/tmp');
    }
}
