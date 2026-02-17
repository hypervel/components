<?php

declare(strict_types=1);

namespace Hypervel\Server;

class Port
{
    protected string $name = 'http';

    protected int $type = ServerInterface::SERVER_HTTP;

    protected string $host = '0.0.0.0';

    protected int $port = 9501;

    protected int $sockType = 0;

    protected array $callbacks = [];

    protected array $settings = [];

    protected ?Option $options = null;

    /**
     * Build a port instance from a configuration array.
     */
    public static function build(array $config): static
    {
        $config = self::filter($config);

        $port = new static();
        isset($config['name']) && $port->setName($config['name']);
        isset($config['type']) && $port->setType($config['type']);
        isset($config['host']) && $port->setHost($config['host']);
        isset($config['port']) && $port->setPort($config['port']);
        isset($config['sock_type']) && $port->setSockType($config['sock_type']);
        isset($config['callbacks']) && $port->setCallbacks($config['callbacks']);
        isset($config['settings']) && $port->setSettings($config['settings']);
        isset($config['options']) && $port->setOptions(Option::make($config['options']));

        return $port;
    }

    /**
     * Get the server name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the server name.
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the server type.
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * Set the server type.
     */
    public function setType(int $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get the server host.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Set the server host.
     */
    public function setHost(string $host): static
    {
        $this->host = $host;
        return $this;
    }

    /**
     * Get the server port.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Set the server port.
     */
    public function setPort(int $port): static
    {
        $this->port = $port;
        return $this;
    }

    /**
     * Get the socket type.
     */
    public function getSockType(): int
    {
        return $this->sockType;
    }

    /**
     * Set the socket type.
     */
    public function setSockType(int $sockType): static
    {
        $this->sockType = $sockType;
        return $this;
    }

    /**
     * Get the server callbacks.
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * Set the server callbacks.
     */
    public function setCallbacks(array $callbacks): static
    {
        $this->callbacks = $callbacks;
        return $this;
    }

    /**
     * Get the server settings.
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Set the server settings.
     */
    public function setSettings(array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * Get the server options.
     */
    public function getOptions(): ?Option
    {
        return $this->options;
    }

    /**
     * Set the server options.
     */
    public function setOptions(Option $options): static
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Apply default settings for base server type.
     */
    private static function filter(array $config): array
    {
        if ((int) $config['type'] === ServerInterface::SERVER_BASE) {
            $default = [
                'open_http2_protocol' => false,
                'open_http_protocol' => false,
            ];

            $config['settings'] = array_merge($default, $config['settings'] ?? []);
        }

        return $config;
    }
}
