<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Server\Entry\EventDispatcher;
use Hypervel\Server\Entry\Logger;
use Hypervel\Contracts\Container\Container;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

class ServerFactory
{
    protected ?LoggerInterface $logger = null;

    protected ?EventDispatcherInterface $eventDispatcher = null;

    protected ?ServerInterface $server = null;

    protected ?ServerConfig $config = null;

    public function __construct(protected Container $container)
    {
    }

    /**
     * Configure the server with the given config array.
     */
    public function configure(array $config): void
    {
        $this->config = new ServerConfig($config);

        $this->getServer()->init($this->config);
    }

    /**
     * Start the server.
     */
    public function start(): void
    {
        $this->getServer()->start();
    }

    /**
     * Get the server instance.
     */
    public function getServer(): ServerInterface
    {
        if (! $this->server instanceof ServerInterface) {
            $this->server = new Server(
                $this->container,
                $this->getLogger(),
                $this->getEventDispatcher()
            );
        }

        return $this->server;
    }

    /**
     * Set the server instance.
     */
    public function setServer(Server $server): static
    {
        $this->server = $server;
        return $this;
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            return $this->eventDispatcher;
        }
        return $this->getDefaultEventDispatcher();
    }

    /**
     * Set the event dispatcher instance.
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): static
    {
        $this->eventDispatcher = $eventDispatcher;
        return $this;
    }

    /**
     * Get the logger instance.
     */
    public function getLogger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }
        return $this->getDefaultLogger();
    }

    /**
     * Set the logger instance.
     */
    public function setLogger(LoggerInterface $logger): static
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the server configuration.
     */
    public function getConfig(): ?ServerConfig
    {
        return $this->config;
    }

    /**
     * Get the default no-op event dispatcher.
     */
    private function getDefaultEventDispatcher(): EventDispatcherInterface
    {
        return new EventDispatcher();
    }

    /**
     * Get the default minimal logger.
     */
    private function getDefaultLogger(): LoggerInterface
    {
        return new Logger();
    }
}
