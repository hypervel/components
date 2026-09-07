<?php

declare(strict_types=1);

namespace Hypervel\Server;

class Event
{
    /**
     * Swoole onStart event.
     */
    public const string ON_START = 'start';

    /**
     * Swoole onWorkerStart event.
     */
    public const string ON_WORKER_START = 'workerStart';

    /**
     * Swoole onWorkerStop event.
     */
    public const string ON_WORKER_STOP = 'workerStop';

    /**
     * Swoole onWorkerExit event.
     */
    public const string ON_WORKER_EXIT = 'workerExit';

    /**
     * Swoole onWorkerError event.
     */
    public const string ON_WORKER_ERROR = 'workerError';

    /**
     * Swoole onPipeMessage event.
     */
    public const string ON_PIPE_MESSAGE = 'pipeMessage';

    /**
     * Swoole onRequest event.
     */
    public const string ON_REQUEST = 'request';

    /**
     * Swoole onReceive event.
     */
    public const string ON_RECEIVE = 'receive';

    /**
     * Swoole onConnect event.
     */
    public const string ON_CONNECT = 'connect';

    /**
     * Swoole onHandshake event.
     */
    public const string ON_HANDSHAKE = 'handshake';

    /**
     * Swoole onOpen event.
     */
    public const string ON_OPEN = 'open';

    /**
     * Swoole onMessage event.
     */
    public const string ON_MESSAGE = 'message';

    /**
     * Swoole onClose event.
     */
    public const string ON_CLOSE = 'close';

    /**
     * Swoole onTask event.
     */
    public const string ON_TASK = 'task';

    /**
     * Swoole onFinish event.
     */
    public const string ON_FINISH = 'finish';

    /**
     * Swoole onShutdown event.
     */
    public const string ON_SHUTDOWN = 'shutdown';

    /**
     * Swoole onPacket event.
     */
    public const string ON_PACKET = 'packet';

    /**
     * Swoole onManagerStart event.
     */
    public const string ON_MANAGER_START = 'managerStart';

    /**
     * Swoole onManagerStop event.
     */
    public const string ON_MANAGER_STOP = 'managerStop';

    /**
     * Before server start, it's not a swoole event.
     */
    public const string ON_BEFORE_START = 'beforeStart';

    /**
     * Determine if the given event is a native Swoole event.
     */
    public static function isSwooleEvent(string $event): bool
    {
        return $event !== self::ON_BEFORE_START;
    }
}
