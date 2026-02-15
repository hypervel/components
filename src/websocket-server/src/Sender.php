<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Engine\WebSocket\FrameInterface;
use Hypervel\Engine\WebSocket\Response as WsResponse;
use Hypervel\WebSocketServer\Exception\InvalidMethodException;
use Psr\Log\LoggerInterface;
use Swoole\Server;

use function Hypervel\Engine\swoole_get_flags_from_frame;

/**
 * @method bool push(int $fd, $data, int $opcode = null, $finish = null)
 * @method bool disconnect(int $fd, int $code = null, string $reason = null)
 */
class Sender
{
    protected LoggerInterface $logger;

    protected ?int $workerId = null;

    public function __construct(protected Container $container)
    {
        $this->logger = $container->make(StdoutLoggerInterface::class);
    }

    /**
     * Proxy push or disconnect calls to the Swoole server.
     */
    public function __call(string $name, array $arguments): bool
    {
        [$fd, $method] = $this->getFdAndMethodFromProxyMethod($name, $arguments);

        if (! $this->proxy($fd, $method, $arguments)) {
            $this->sendPipeMessage($name, $arguments);
        }
        return true;
    }

    /**
     * Push a WebSocket frame to a file descriptor.
     */
    public function pushFrame(int $fd, FrameInterface $frame): bool
    {
        if ($this->check($fd)) {
            return (new WsResponse($this->getServer()))->init($fd)->push($frame);
        }

        $this->sendPipeMessage('push', [$fd, (string) $frame->getPayloadData(), $frame->getOpcode(), swoole_get_flags_from_frame($frame)]);
        return false;
    }

    /**
     * Proxy a method call to the Swoole server for a specific file descriptor.
     */
    public function proxy(int $fd, string $method, array $arguments): bool
    {
        $result = $this->check($fd);
        if ($result) {
            /** @var \Swoole\WebSocket\Server $server */
            $server = $this->getServer();
            $result = $server->{$method}(...$arguments);
            $this->logger->debug(
                sprintf(
                    "[WebSocket] Worker.{$this->workerId} send to #{$fd}.Send %s",
                    $result ? 'success' : 'failed'
                )
            );
        }

        return $result;
    }

    /**
     * Set the current worker ID.
     */
    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
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
    protected function getServer(): Server
    {
        return $this->container->make(Server::class);
    }

    /**
     * Send a pipe message to all other workers.
     */
    protected function sendPipeMessage(string $name, array $arguments): void
    {
        $server = $this->getServer();
        $workerCount = $server->setting['worker_num'] - 1;
        for ($workerId = 0; $workerId <= $workerCount; ++$workerId) {
            if ($workerId !== $this->workerId) {
                $server->sendMessage(new SenderPipeMessage($name, $arguments), $workerId);
                $this->logger->debug("[WebSocket] Let Worker.{$workerId} try to {$name}.");
            }
        }
    }
}
