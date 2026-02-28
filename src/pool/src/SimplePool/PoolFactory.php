<?php

declare(strict_types=1);

namespace Hypervel\Pool\SimplePool;

use Hypervel\Contracts\Container\Container;

/**
 * Factory for creating and managing simple pools.
 */
class PoolFactory
{
    /**
     * @var array<string, Pool>
     */
    protected array $pools = [];

    /**
     * @var array<string, Config>
     */
    protected array $configs = [];

    public function __construct(
        protected Container $container
    ) {
    }

    public function addConfig(Config $config): static
    {
        $this->configs[$config->getName()] = $config;

        return $this;
    }

    /**
     * @param array<string, mixed> $option
     */
    public function get(string $name, callable $callback, array $option = []): Pool
    {
        if (! $this->hasConfig($name)) {
            $config = new Config($name, $callback, $option);
            $this->addConfig($config);
        }

        $config = $this->getConfig($name);

        if (! isset($this->pools[$name])) {
            $this->pools[$name] = new Pool(
                $this->container,
                $config->getCallback(),
                $config->getOption()
            );
        }

        return $this->pools[$name];
    }

    /**
     * @return string[]
     */
    public function getPoolNames(): array
    {
        return array_keys($this->pools);
    }

    protected function hasConfig(string $name): bool
    {
        return isset($this->configs[$name]);
    }

    protected function getConfig(string $name): Config
    {
        return $this->configs[$name];
    }
}
