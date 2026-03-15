<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Server\Exceptions\InvalidArgumentException;

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
        if (in_array($prefix, ['set', 'get'])) {
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
        $this->config['servers'][] = $port;
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
        $this->config[$name] = $value;
        return $this;
    }

    /**
     * Determine if the given property name is valid.
     */
    private function isAvailableProperty(string $name): bool
    {
        return in_array($name, [
            'type', 'mode', 'servers', 'processes', 'settings', 'callbacks',
        ]);
    }
}
