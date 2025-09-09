<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Application;
use Sentry\SentrySdk;
use Throwable;

abstract class Feature
{
    /**
     * In-memory cache for the tracing feature flag.
     *
     * @var array<string, bool>
     */
    protected array $isTracingFeatureEnabled = [];

    /**
     * In-memory cache for the breadcrumb feature flag.
     *
     * @var array<string, bool>
     */
    protected array $isBreadcrumbFeatureEnabled = [];

    public function __construct(protected Application $container)
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
     * Retrieve the Laravel application container.
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
        $config = $this->container->get(ConfigInterface::class)->get('sentry', []);

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

    /**
     * Indicates if the given feature is enabled for tracing.
     */
    protected function isTracingFeatureEnabled(string $feature, bool $default = true): bool
    {
        return true;
        if (! array_key_exists($feature, $this->isTracingFeatureEnabled)) {
            $this->isTracingFeatureEnabled[$feature] = $this->isFeatureEnabled('tracing', $feature, $default);
        }

        return $this->isTracingFeatureEnabled[$feature];
    }

    /**
     * Indicates if the given feature is enabled for breadcrumbs.
     */
    protected function isBreadcrumbFeatureEnabled(string $feature, bool $default = true): bool
    {
        if (! array_key_exists($feature, $this->isBreadcrumbFeatureEnabled)) {
            $this->isBreadcrumbFeatureEnabled[$feature] = $this->isFeatureEnabled('breadcrumbs', $feature, $default);
        }

        return $this->isBreadcrumbFeatureEnabled[$feature];
    }

    /**
     * Helper to test if a certain feature is enabled in the user config.
     */
    private function isFeatureEnabled(string $category, string $feature, bool $default): bool
    {
        $config = $this->getUserConfig()[$category] ?? [];

        return ($config[$feature] ?? $default) === true;
    }
}
