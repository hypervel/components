<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Foundation\Application;
use Hypervel\Sentry\Switcher;
use Sentry\SentrySdk;
use Throwable;

abstract class Feature
{
    public function __construct(protected Application $container, protected Switcher $switcher)
    {
    }

    /**
     * Indicates if the feature is applicable to the current environment.
     */
    abstract public function isApplicable(): bool;

    /**
     * Register the feature in the environment.
     */
    public function register(): void
    {
        // ...
    }

    public function onBoot(): void
    {
        // ...
    }

    public function onBootInactive(): void
    {
    }

    /**
     * Initialize the feature.
     */
    public function boot(): void
    {
        if ($this->isApplicable()) {
            try {
                $this->onBoot();
            } catch (Throwable $exception) {
                // If the feature setup fails, we don't want to prevent the rest of the SDK from working.
            }
        }
    }

    /**
     * Initialize the feature in an inactive state (when no DSN was set).
     */
    public function bootInactive(): void
    {
        if ($this->isApplicable()) {
            try {
                $this->onBootInactive();
            } catch (Throwable $exception) {
                // If the feature setup fails, we don't want to prevent the rest of the SDK from working.
            }
        }
    }

    /**
     * Retrieve the Hypervel application container.
     */
    protected function container(): Application
    {
        return $this->container;
    }

    /**
     * Retrieve the user configuration.
     */
    protected function getUserConfig(): array
    {
        $config = $this->container->get('config')->get('sentry', []);

        return empty($config) ? [] : $config;
    }

    /**
     * Should default PII be sent by default.
     */
    protected function shouldSendDefaultPii(): bool
    {
        $client = SentrySdk::getCurrentHub()->getClient();

        if ($client === null) {
            return false;
        }

        return $client->getOptions()->shouldSendDefaultPii();
    }
}
