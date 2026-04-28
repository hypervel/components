<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

use Hypervel\Contracts\Container\Container;
use Hypervel\Reverb\Servers\Hypervel\HypervelServerProvider;
use Hypervel\Support\Manager;

/**
 * @method void register()
 * @method void boot()
 * @method bool shouldPublishEvents()
 * @method bool shouldNotPublishEvents()
 * @method bool subscribesToEvents()
 * @method bool doesNotSubscribeToEvents()
 * @method void withPublishing()
 */
class ServerProviderManager extends Manager
{
    /**
     * Create a new server manager instance.
     */
    public function __construct(protected Container $container)
    {
        parent::__construct($container);
    }

    /**
     * Create the Reverb driver.
     */
    public function createReverbDriver(): HypervelServerProvider
    {
        return new HypervelServerProvider(
            $this->container,
            $this->config->get('reverb.servers.reverb', [])
        );
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('reverb.default', 'reverb');
    }
}
