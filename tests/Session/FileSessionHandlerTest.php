<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Session\FileSessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;

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

    public function testOpen(): void
    {
        $this->assertTrue($this->sessionHandler->open('/path/to/sessions', 'session_name'));
    }

    public function testClose(): void
    {
        $this->assertTrue($this->sessionHandler->close());
    }

    public function testReadReturnsDataWhenFileExistsAndIsValid(): void
    {
        $sessionId = 'session_id';
        $path = '/path/to/sessions/' . $sessionId;
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-02 01:30:00'));

        $this->files->shouldReceive('isFile')->with($path)->andReturn(true);

        $minutesAgo30 = CarbonImmutable::parse('2025-02-02 01:00:00')->getTimestamp();
        $this->files->shouldReceive('lastModified')->with($path)->andReturn($minutesAgo30);
        $this->files->shouldReceive('sharedGet')->with($path)->once()->andReturn('session_data');

        $result = $this->sessionHandler->read($sessionId);

        $this->assertSame('session_data', $result);
    }

    public function testReadReturnsEmptyWhenFileExistsButExpired(): void
    {
        $sessionId = 'session_id';
        $path = '/path/to/sessions/' . $sessionId;
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-02 01:30:01'));

        $this->files->shouldReceive('isFile')->with($path)->andReturn(true);

        $minutesAgo30 = CarbonImmutable::parse('2025-02-02 01:00:00')->getTimestamp();
        $this->files->shouldReceive('lastModified')->with($path)->andReturn($minutesAgo30);
        $this->files->shouldReceive('sharedGet')->never();

        $result = $this->sessionHandler->read($sessionId);

        $this->assertSame('', $result);
    }

    public function testReadReturnsEmptyStringWhenFileDoesNotExist(): void
    {
        $sessionId = 'non_existing_session_id';
        $path = '/path/to/sessions/' . $sessionId;

        $this->files->shouldReceive('isFile')->with($path)->andReturn(false);

        $result = $this->sessionHandler->read($sessionId);

        $this->assertSame('', $result);
    }

    public function testReadReturnsEmptyStringWhenTheSessionFileDisappearsBeforeReading(): void
    {
        $sessionId = 'vanished_session_id';
        $path = '/path/to/sessions/' . $sessionId;
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-02 01:30:00'));

        $this->files->shouldReceive('isFile')->with($path)->andReturnTrue();
        $this->files->shouldReceive('lastModified')->with($path)->andReturn(CarbonImmutable::now()->getTimestamp());
        $this->files->shouldReceive('sharedGet')->with($path)->once()->andThrow(
            new FileNotFoundException("Unable to read file at path {$path}.")
        );

        $this->assertSame('', $this->sessionHandler->read($sessionId));
    }

    public function testWriteStoresData(): void
    {
        $sessionId = 'session_id';
        $data = 'session_data';

        $this->files->shouldReceive('put')->with('/path/to/sessions/' . $sessionId, $data, true)->once()->andReturn(strlen($data));

        $result = $this->sessionHandler->write($sessionId, $data);

        $this->assertTrue($result);
    }

    public function testWriteRejectsFalseAndShortFilesystemWrites(): void
    {
        $data = 'session_data';

        foreach ([false, strlen($data) - 1] as $written) {
            $files = m::mock(Filesystem::class);
            $files->shouldReceive('put')
                ->once()
                ->with('/path/to/sessions/session_id', $data, true)
                ->andReturn($written);

            $handler = new FileSessionHandler($files, '/path/to/sessions', 30);

            $this->assertFalse($handler->write('session_id', $data));
        }
    }

    public function testFailedFileWriteLeavesLiveSessionStateUntouched(): void
    {
        $sessionId = str_repeat('a', 40);
        $path = '/path/to/sessions/' . $sessionId;
        $this->files->shouldReceive('isFile')->once()->with($path)->andReturnFalse();
        $this->files->shouldReceive('put')->once()->with($path, m::type('string'), true)->andReturnFalse();

        $session = new Store('name', $this->sessionHandler, $sessionId);
        $session->start();
        $session->flash('status', 'saved');

        try {
            $session->save();

            $this->fail('Expected the failed file write to reject the session save.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the session data.', $exception->getMessage());
        }

        $this->assertTrue($session->isStarted());
        $this->assertSame(['status'], $session->get('_flash.new'));
    }

    public function testDestroyDeletesSessionFile(): void
    {
        $sessionId = 'session_id';

        $this->files->shouldReceive('delete')->with('/path/to/sessions/' . $sessionId)->once()->andReturn(true);

        $result = $this->sessionHandler->destroy($sessionId);

        $this->assertTrue($result);
    }

    public function testGcDeletesOldSessionFiles(): void
    {
        $tempDir = ParallelTesting::tempDir('FileSessionHandlerTest');
        mkdir($tempDir, 0777, true);

        try {
            $session = new FileSessionHandler($this->files, $tempDir, 30);

            $this->files->shouldReceive('delete')->with(join_paths($tempDir, 'a2'))->once()->andReturn(false);
            $this->files->shouldReceive('delete')->with(join_paths($tempDir, 'a3'))->once()->andReturn(true);

            touch(join_paths($tempDir, 'a1'), time() - 3); // last modified: 3 sec ago
            touch(join_paths($tempDir, 'a2'), time() - 5); // last modified: 5 sec ago
            touch(join_paths($tempDir, 'a3'), time() - 7); // last modified: 7 sec ago

            $this->assertSame(1, $session->gc(5));
        } finally {
            (new Filesystem)->deleteDirectory($tempDir);
        }
    }
}
