<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Contracts\Config\Repository;
use Sentry\Options;

/**
 * @internal
 */
class SentryConfig
{
    private const array BREADCRUMB_DEFAULTS = [
        'logs' => true,
        'cache' => true,
        'storage' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_transactions' => true,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ];

    private const array TRACING_DEFAULTS = [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => true,
        'sql_origin_threshold_ms' => 100,
        'views' => true,
        'http_client_requests' => true,
        'cache' => true,
        'storage' => true,
        'redis_commands' => false,
        'redis_origin' => true,
        'notifications' => true,
        'missing_routes' => false,
        'continue_after_response' => true,
    ];

    /**
     * Create a new Sentry configuration reader.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly string $root,
    ) {
    }

    /**
     * Determine if a DSN is configured.
     */
    public function hasDsnSet(): bool
    {
        return self::configHasDsn($this->all());
    }

    /**
     * Determine if Spotlight is enabled.
     */
    public function hasSpotlightEnabled(): bool
    {
        return self::configHasSpotlightEnabled($this->all());
    }

    /**
     * Determine if the SDK can record spans.
     */
    public function canRecordSpans(): bool
    {
        $config = $this->all();
        $enableTracing = $config['enable_tracing'] ?? null;
        $tracesSampleRate = $config['traces_sample_rate'];

        // Mirror Options::__construct()'s legacy enable_tracing default and Options::isTracingEnabled().
        $tracingEnabled = $enableTracing === true
            || ($enableTracing !== false
                && ($tracesSampleRate !== null
                    || ($config['traces_sampler'] ?? null) !== null));

        return self::configHasActiveEndpoint($config) && $tracingEnabled;
    }

    /**
     * Determine if the SDK can record breadcrumbs.
     */
    public function canRecordBreadcrumbs(): bool
    {
        $config = $this->all();

        return self::configHasActiveEndpoint($config)
            && ($config['max_breadcrumbs'] ?? Options::DEFAULT_MAX_BREADCRUMBS) > 0;
    }

    /**
     * Retrieve the merged Sentry configuration.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $config = $this->config->array($this->root);
        $config['breadcrumbs'] = $this->config->array($this->root . '.breadcrumbs', [])
            + self::BREADCRUMB_DEFAULTS;
        $config['tracing'] = $this->config->array($this->root . '.tracing', [])
            + self::TRACING_DEFAULTS;

        return $config;
    }

    /**
     * Determine if the given configuration contains a DSN.
     *
     * @param array<string, mixed> $config
     */
    private static function configHasDsn(array $config): bool
    {
        $dsn = $config['dsn'];

        return ! empty($dsn);
    }

    /**
     * Determine if Spotlight is enabled in the given configuration.
     *
     * @param array<string, mixed> $config
     */
    private static function configHasSpotlightEnabled(array $config): bool
    {
        $spotlight = $config['spotlight'];

        // Match the SDK's disabled handling for the environment string '0'.
        return $spotlight === true || (is_string($spotlight) && ! empty($spotlight));
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
