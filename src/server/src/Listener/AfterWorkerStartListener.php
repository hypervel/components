<?php

declare(strict_types=1);

namespace Hypervel\Server\Listener;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Constant\SocketType;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\Server\ServerInterface;
use Hypervel\Server\ServerManager;
use Psr\Log\LoggerInterface;
use Swoole\Server\Port;

class AfterWorkerStartListener
{
    private LoggerInterface $logger;

    public function __construct(StdoutLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Log server listening information after the first worker starts.
     */
    public function handle(AfterWorkerStart $event): void
    {
        if ($event->workerId === 0) {
            /** @var Port $server */
            foreach (ServerManager::list() as [$type, $server]) {
                $listen = $server->host . ':' . $server->port;
                $type = match ($type) {
                    ServerInterface::SERVER_BASE => $this->resolveBaseServerType($server->type),
                    ServerInterface::SERVER_WEBSOCKET => 'WebSocket',
                    default => 'HTTP',
                };
                $this->logger->info(sprintf('%s Server listening at %s', $type, $listen));
            }
        }
    }

    /**
     * Resolve the display type for a base server by its socket type.
     */
    private function resolveBaseServerType(int $sockType): string
    {
        if (in_array($sockType, [SocketType::TCP, SocketType::TCP6])) {
            return 'TCP';
        }
        if (in_array($sockType, [SocketType::UDP, SocketType::UDP6])) {
            return 'UDP';
        }

        return 'UNKNOWN';
    }
}
