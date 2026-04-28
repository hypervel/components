<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess\Fixtures;

use Hypervel\Engine\Channel;
use Hypervel\ServerProcess\AbstractProcess;
use Swoole\Coroutine\Socket;

class ListenableProcess extends AbstractProcess
{
    public bool $enableCoroutine = false;

    public int $restartInterval = 0;

    /**
     * The fake socket to use in listen().
     */
    public ?FakeSocket $fakeSocket = null;

    public function handle(): void
    {
        // No-op.
    }

    /**
     * Expose listen() for testing.
     */
    public function callListen(Channel $quit): void
    {
        $this->listen($quit);
    }

    /**
     * Return the fake socket instead of calling exportSocket().
     */
    protected function getListenSocket(): Socket
    {
        return $this->fakeSocket;
    }
}
