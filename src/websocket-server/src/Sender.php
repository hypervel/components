<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Engine\WebSocket\FrameInterface;
use Hypervel\Engine\WebSocket\Response as WsResponse;
use Hypervel\WebSocketServer\Exceptions\InvalidMethodException;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

use function Hypervel\Engine\swoole_get_flags_from_frame;

/**
 * @method bool push(int $fd, Frame|string $data, int $opcode = 1, int $flags = 1)
 * @method bool disconnect(int $fd, int $code = 1000, string $reason = '')
 */
class Sender
{
    protected ?WebSocketServer $server = null;

    public function __construct(protected Container $container)
    {
    }

    /**
     * Proxy push or disconnect calls to the Swoole server.
     */
    public function __call(string $name, array $arguments): bool
    {
        [$fd, $method] = $this->getFdAndMethodFromProxyMethod($name, $arguments);
        $result = $this->proxy($fd, $method, $arguments);

        if ($result !== null) {
            return $result;
        }

        if ($this->getServer()->mode !== SWOOLE_BASE) {
            return false;
        }

        return $this->sendPipeMessage($name, $arguments);
    }

    /**
     * Push a WebSocket frame to a file descriptor.
     */
    public function pushFrame(int $fd, FrameInterface $frame): bool
    {
        if ($this->check($fd)) {
            return (new WsResponse($this->getServer()))->init($fd)->push($frame);
        }

        if ($this->getServer()->mode !== SWOOLE_BASE) {
            return false;
        }

        return $this->sendPipeMessage('push', [$fd, (string) $frame->getPayloadData(), $frame->getOpcode(), swoole_get_flags_from_frame($frame)]);
    }

    /**
     * Proxy a method call to the Swoole server for a specific file descriptor.
     */
    public function proxy(int $fd, string $method, array $arguments): ?bool
    {
        if (! $this->check($fd)) {
            return null;
        }

        return $this->getServer()->{$method}(...$arguments);
    }

    /**
     * Check if a file descriptor has an active WebSocket connection.
     */
    public function check(int $fd): bool
    {
        $info = $this->getServer()->connection_info($fd);

        if (($info['websocket_status'] ?? null) === WEBSOCKET_STATUS_ACTIVE) {
            return true;
        }

        return false;
    }

    /**
     * Validate and extract the file descriptor and method name from a proxy call.
     *
     * @return array{int, string}
     */
    public function getFdAndMethodFromProxyMethod(string $method, array $arguments): array
    {
        if (! in_array($method, ['push', 'disconnect'])) {
            throw new InvalidMethodException(sprintf('Method [%s] is not allowed.', $method));
        }

        return [(int) $arguments[0], $method];
    }

    /**
     * Get the Swoole server instance.
     */
    protected function getServer(): WebSocketServer
    {
        if ($this->server === null) {
            /** @var WebSocketServer $server */
            $server = $this->container->make(Server::class);
            $this->server = $server;
        }

        return $this->server;
    }

    /**
     * Send a pipe message to all other workers.
     */
    protected function sendPipeMessage(string $name, array $arguments): bool
    {
        $server = $this->getServer();
        $workerCount = (int) ($server->setting['worker_num'] ?? 1);
        $recipientCount = 0;
        $accepted = true;

        for ($workerId = 0; $workerId < $workerCount; ++$workerId) {
            if ($workerId !== $server->worker_id) {
                ++$recipientCount;

                if (! $server->sendMessage(new SenderPipeMessage($name, $arguments), $workerId)) {
                    $accepted = false;
                }
            }
        }

        return $recipientCount > 0 && $accepted;
    }
}
