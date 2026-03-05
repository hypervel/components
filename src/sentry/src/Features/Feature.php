<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Contracts\Container\Container;
use Sentry\SentrySdk;
use Throwable;

/**
 * @internal
 */
abstract class Feature
{
    /**
     * In-memory cache for tracing feature flags.
     *
     * @var array<string, bool>
     */
    private array $isTracingFeatureEnabled = [];

    /**
     * In-memory cache for breadcrumb feature flags.
     *
     * @var array<string, bool>
     */
    private array $isBreadcrumbFeatureEnabled = [];

    /**
     * Create a new feature instance.
     */
    public function __construct(
        protected readonly Container $container,
    ) {
    }

    /**
     * Indicate if the feature is applicable to the current environment.
     */
    abstract public function isApplicable(): bool;

    /**
     * Register the feature in the environment.
     */
    public function register(): void
    {
        // ...
    }

    /**
     * Set up the feature in the environment.
     */
    public function onBoot(): void
    {
        // ...
    }

    /**
     * Set up the feature in the environment in an inactive state (when no DSN was set).
     */
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
            } catch (Throwable) {
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
            } catch (Throwable) {
                // If the feature setup fails, we don't want to prevent the rest of the SDK from working.
            }
        }
    }

    /**
     * Retrieve the user configuration.
     */
    protected function getUserConfig(): array
    {
        $config = $this->container->make('config')->get('sentry', []);

        return empty($config) ? [] : $config;
    }

    /**
     * Determine if default PII should be sent.
     */
    protected function shouldSendDefaultPii(): bool
    {
        $client = SentrySdk::getCurrentHub()->getClient();

        if ($client === null) {
            return false;
        }

        return $client->getOptions()->shouldSendDefaultPii();
    }

    /**
     * Indicate if the given feature is enabled for tracing.
     */
    protected function isTracingFeatureEnabled(string $feature, bool $default = true): bool
    {
        if (! array_key_exists($feature, $this->isTracingFeatureEnabled)) {
            $this->isTracingFeatureEnabled[$feature] = $this->isFeatureEnabled('tracing', $feature, $default);
        }

        return $this->isTracingFeatureEnabled[$feature];
    }

    /**
     * Indicate if the given feature is enabled for breadcrumbs.
     */
    protected function isBreadcrumbFeatureEnabled(string $feature, bool $default = true): bool
    {
        if (! array_key_exists($feature, $this->isBreadcrumbFeatureEnabled)) {
            $this->isBreadcrumbFeatureEnabled[$feature] = $this->isFeatureEnabled('breadcrumbs', $feature, $default);
        }

        return $this->isBreadcrumbFeatureEnabled[$feature];
    }

    /**
     * Test if a certain feature is enabled in the user config.
     */
    private function isFeatureEnabled(string $category, string $feature, bool $default): bool
    {
        $config = $this->getUserConfig()[$category] ?? [];

        return ($config[$feature] ?? $default) === true;
    }
}
