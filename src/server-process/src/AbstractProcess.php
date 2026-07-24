<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\ServerProcess\ProcessInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\Events\PipeMessage;
use Hypervel\ServerProcess\Exceptions\SocketAcceptException;
use Hypervel\Support\Sleep;
use RuntimeException;
use Swoole\Coroutine\Socket;
use Swoole\Process as SwooleProcess;
use Swoole\Server;
use Swoole\Timer;
use Throwable;

abstract class AbstractProcess implements ProcessInterface
{
    public string $name = 'process';

    public int $processCount = 1;

    public bool $redirectStdinStdout = false;

    public int $pipeType = SOCK_DGRAM;

    public bool $enableCoroutine = true;

    protected ?DispatcherContract $event = null;

    protected ?SwooleProcess $process = null;

    protected int $receiveLength = 65535;

    protected float $receiveTimeout = 10.0;

    protected int $restartInterval = 5;

    public function __construct(protected Container $container)
    {
        if ($container->bound('events')) {
            $this->event = $container->make('events');
        }
    }

    /**
     * Determine if the process should start.
     */
    public function isEnabled(Server $server): bool
    {
        return true;
    }

    /**
     * Create process objects and bind them to the server.
     */
    public function bind(Server $server): void
    {
        for ($i = 0; $i < $this->processCount; ++$i) {
            $process = new SwooleProcess(function (SwooleProcess $process) use ($i) {
                $exception = null;

                try {
                    $this->event?->dispatch(new BeforeProcessHandle($this, $i));

                    $this->process = $process;
                    if ($this->enableCoroutine) {
                        $quit = new Channel(1);
                        $this->listen($quit);
                    }
                    $this->handle();
                } catch (Throwable $throwable) {
                    try {
                        $this->logThrowable($throwable);
                    } catch (Throwable $reportingException) {
                        $exception = $reportingException;
                    }
                } finally {
                    try {
                        $this->event?->dispatch(new AfterProcessHandle($this, $i));
                    } catch (Throwable $throwable) {
                        $exception ??= $throwable;
                    }

                    if (isset($quit)) {
                        try {
                            $quit->push(true);
                        } catch (Throwable $throwable) {
                            $exception ??= $throwable;
                        }
                    }

                    try {
                        Timer::clearAll();
                    } catch (Throwable $throwable) {
                        $exception ??= $throwable;
                    }

                    try {
                        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
                    } catch (Throwable $throwable) {
                        $exception ??= $throwable;
                    }

                    try {
                        Sleep::sleep($this->restartInterval);
                    } catch (Throwable $throwable) {
                        $exception ??= $throwable;
                    }
                }

                if ($exception !== null) {
                    throw $exception;
                }
            }, $this->redirectStdinStdout, $this->pipeType, $this->enableCoroutine);
            $process->setBlocking(false);

            try {
                if ($server->addProcess($process) === false) {
                    throw new RuntimeException(sprintf('Unable to register server process [%s.%d].', $this->name, $i));
                }
            } catch (Throwable $exception) {
                if ($this->pipeType !== 0) {
                    // Preserve the registration failure if native pipe cleanup also fails.
                    try {
                        @$process->close();
                    } catch (Throwable) {
                    }
                }

                throw $exception;
            }

            if ($this->enableCoroutine) {
                ProcessCollector::add($this->name, $process);
            }
        }
    }

    /**
     * Listen for data from worker/task processes via IPC pipe.
     */
    protected function listen(Channel $quit): void
    {
        try {
            Coroutine::create(function () use ($quit) {
                try {
                    try {
                        $socket = $this->getListenSocket();

                        if ($socket === false) {
                            throw new SocketAcceptException('Unable to export process IPC socket', permanent: true);
                        }
                    } catch (Throwable $exception) {
                        $this->logThrowable($exception);

                        return;
                    }

                    while ($quit->pop(0.001) !== true) {
                        try {
                            $received = $socket->recv($this->receiveLength, $this->receiveTimeout);

                            // Empty string means the peer closed the pipe — permanent, stop listening.
                            if ($received === '') {
                                throw new SocketAcceptException('Socket is closed', $socket->errCode, permanent: true);
                            }

                            if ($received === false && $socket->errCode !== SOCKET_ETIMEDOUT) {
                                // Signal interruption or temporarily unavailable — transient, retry.
                                $transient = $socket->errCode === SOCKET_EINTR || $socket->errCode === SOCKET_EAGAIN;

                                throw new SocketAcceptException(
                                    $transient ? 'Socket recv error' : 'Socket is closed',
                                    $socket->errCode,
                                    permanent: ! $transient,
                                );
                            }

                            if ($received === false || ! $this->event) {
                                continue;
                            }

                            $data = unserialize($received);

                            if ($data !== false || $received === 'b:0;') {
                                $this->event->dispatch(new PipeMessage($data));
                            }
                        } catch (Throwable $exception) {
                            $this->logThrowable($exception);
                            if ($exception instanceof SocketAcceptException && $exception->isPermanent()) {
                                break;
                            }
                        }
                    }
                } finally {
                    $quit->close();
                }
            });
        } catch (CoroutineCreateException $exception) {
            $quit->close();

            throw $exception;
        }
    }

    /**
     * Get the socket for the IPC pipe listener.
     */
    protected function getListenSocket(): Socket|false
    {
        return @$this->process->exportSocket();
    }

    /**
     * Log a throwable via the exception handler.
     */
    protected function logThrowable(Throwable $throwable): void
    {
        if ($this->container->has(ExceptionHandlerContract::class)) {
            $this->container->make(ExceptionHandlerContract::class)->report($throwable);
        }
    }
}
