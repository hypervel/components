<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Contracts\Config\Repository;
use Sentry\Options;

/**
 * @internal
 */
class SdkCapabilities
{
    /**
     * Create a new SDK capability reader.
     */
    public function __construct(
        private readonly Repository $config,
    ) {
    }

    /**
     * Determine if a DSN is configured.
     */
    public function hasDsnSet(): bool
    {
        return self::configHasDsn($this->userConfig());
    }

    /**
     * Determine if Spotlight is enabled.
     */
    public function hasSpotlightEnabled(): bool
    {
        return self::configHasSpotlightEnabled($this->userConfig());
    }

    /**
     * Determine if the SDK can record spans.
     */
    public function canRecordSpans(): bool
    {
        $config = $this->userConfig();
        $enableTracing = $config['enable_tracing'] ?? null;

        // Mirror Options::__construct()'s legacy enable_tracing default and Options::isTracingEnabled().
        $tracingEnabled = $enableTracing === true
            || ($enableTracing !== false
                && (($config['traces_sample_rate'] ?? null) !== null
                    || ($config['traces_sampler'] ?? null) !== null));

        return self::configHasActiveEndpoint($config) && $tracingEnabled;
    }

    /**
     * Determine if the SDK can record breadcrumbs.
     */
    public function canRecordBreadcrumbs(): bool
    {
        $config = $this->userConfig();

        return self::configHasActiveEndpoint($config)
            && ($config['max_breadcrumbs'] ?? Options::DEFAULT_MAX_BREADCRUMBS) > 0;
    }

    /**
     * Retrieve the merged Sentry configuration.
     *
     * @return array<string, mixed>
     */
    private function userConfig(): array
    {
        return $this->config->array('sentry', []);
    }

    /**
     * Determine if the given configuration contains a DSN.
     *
     * @param array<string, mixed> $config
     */
    private static function configHasDsn(array $config): bool
    {
        return ! empty($config['dsn']);
    }

    /**
     * Determine if Spotlight is enabled in the given configuration.
     *
     * @param array<string, mixed> $config
     */
    private static function configHasSpotlightEnabled(array $config): bool
    {
        $spotlight = $config['spotlight'] ?? false;

        return $spotlight === true || (is_string($spotlight) && $spotlight !== '');
    }

    /**
     * Determine if the given configuration has an active endpoint.
     *
     * @param array<string, mixed> $config
     */
    private static function configHasActiveEndpoint(array $config): bool
    {
        return self::configHasDsn($config) || self::configHasSpotlightEnabled($config);
    }
}
