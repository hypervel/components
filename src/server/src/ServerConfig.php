<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Server\Exceptions\InvalidArgumentException;
use Swoole\Constant;

/**
 * @method ServerConfig setType(string $type)
 * @method ServerConfig setMode(int $mode)
 * @method ServerConfig setServers(array $servers)
 * @method ServerConfig setProcesses(array $processes)
 * @method ServerConfig setSettings(array $settings)
 * @method ServerConfig setCallbacks(array $callbacks)
 * @method string getType()
 * @method int getMode()
 * @method Port[] getServers()
 * @method array getProcesses()
 * @method array getSettings()
 * @method array getCallbacks()
 */
class ServerConfig implements Arrayable
{
    public function __construct(protected array $config = [])
    {
        if (empty($config['servers'] ?? [])) {
            throw new InvalidArgumentException('Config server.servers not exist.');
        }

        $servers = [];

        foreach ($config['servers'] as $name => $item) {
            if (! isset($item['name']) && ! is_numeric($name)) {
                $item['name'] = $name;
            }

            $servers[] = Port::build($item);
        }

        $this->setType($config['type'] ?? Server::class)
            ->setMode($config['mode'] ?? 0)
            ->setServers($servers)
            ->setProcesses($config['processes'] ?? [])
            ->setSettings($config['settings'] ?? [])
            ->setCallbacks($config['callbacks'] ?? []);
    }

    /**
     * Dynamically set a configuration property.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Dynamically get a configuration property.
     */
    public function __get(string $name): mixed
    {
        if (! $this->isAvailableProperty($name)) {
            throw new \InvalidArgumentException(sprintf('Invalid property %s', $name));
        }
        return $this->config[$name] ?? null;
    }

    /**
     * Handle dynamic getter and setter calls.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $prefix = strtolower(substr($name, 0, 3));
        if (in_array($prefix, ['set', 'get'], true)) {
            $propertyName = strtolower(substr($name, 3));
            if (! $this->isAvailableProperty($propertyName)) {
                throw new \InvalidArgumentException(sprintf('Invalid property %s', $propertyName));
            }
            return $prefix === 'set' ? $this->set($propertyName, ...$arguments) : $this->__get($propertyName);
        }

        throw new \InvalidArgumentException(sprintf('Invalid method %s', $name));
    }

    /**
     * Add a server port to the configuration.
     */
    public function addServer(Port $port): static
    {
        $this->set('servers', [...$this->getServers(), $port]);

        return $this;
    }

    /**
     * Get the configuration as an array.
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Set a configuration property by name.
     */
    protected function set(string $name, mixed $value): self
    {
        if (! $this->isAvailableProperty($name)) {
            throw new \InvalidArgumentException(sprintf('Invalid property %s', $name));
        }

        if ($name === 'servers') {
            $value = $this->validateServers($value);
        }

        if ($name === 'settings'
            && is_array($value)
            && ! empty($value[Constant::OPTION_EVENT_OBJECT])) {
            throw new InvalidArgumentException(
                'Swoole event_object is not supported in global server settings; use Hypervel lifecycle events instead.'
            );
        }

        $this->config[$name] = $value;
        return $this;
    }

    /**
     * Validate the complete server port list.
     *
     * @return list<Port>
     */
    private function validateServers(mixed $servers): array
    {
        if (! is_array($servers) || $servers === []) {
            throw new InvalidArgumentException('Config server.servers not exist.');
        }

        $serverNames = [];

        foreach ($servers as $server) {
            if (! $server instanceof Port) {
                throw new InvalidArgumentException('Server configurations must contain Port instances.');
            }

            $serverName = $server->getName();

            if (trim($serverName) === '') {
                throw new InvalidArgumentException('Server names cannot be empty.');
            }

            if (array_key_exists($serverName, $serverNames)) {
                throw new InvalidArgumentException("Server name [{$serverName}] is duplicated.");
            }

            $serverNames[$serverName] = true;
        }

        return array_values($servers);
    }

    /**
     * Determine if the given property name is valid.
     */
    private function isAvailableProperty(string $name): bool
    {
        return in_array($name, [
            'type', 'mode', 'servers', 'processes', 'settings', 'callbacks',
        ], true);
    }
}
