<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

class Application
{
    /**
     * The default application activity timeout in seconds.
     */
    public const int DEFAULT_ACTIVITY_TIMEOUT = 30;

    /**
     * The default client-event sender policy.
     */
    public const string DEFAULT_ACCEPT_CLIENT_EVENTS_FROM = 'members';

    /**
     * Create a new application instance.
     */
    public function __construct(
        protected string $id,
        protected string $key,
        protected string $secret,
        protected int $pingInterval,
        protected int $activityTimeout,
        protected array $allowedOrigins,
        protected int $maxMessageSize,
        protected ?int $maxConnections = null,
        protected string $acceptClientEventsFrom = self::DEFAULT_ACCEPT_CLIENT_EVENTS_FROM,
        protected ?array $rateLimiting = null,
        protected array $options = [],
        protected array $webhooks = [],
    ) {
        if ($this->rateLimiting !== null) {
            $this->rateLimiting += [
                'enabled' => false,
                'max_attempts' => 60,
                'decay_seconds' => 60,
                'terminate_on_limit' => false,
            ];

            $this->rateLimiting['enabled'] = (bool) $this->rateLimiting['enabled'];
            $this->rateLimiting['max_attempts'] = (int) $this->rateLimiting['max_attempts'];
            $this->rateLimiting['decay_seconds'] = (int) $this->rateLimiting['decay_seconds'];
            $this->rateLimiting['terminate_on_limit'] = (bool) $this->rateLimiting['terminate_on_limit'];
        }

        if ($this->webhooks !== []) {
            $this->webhooks += [
                'url' => null,
                'events' => [],
                'headers' => [],
                'filter' => [],
                'subscription_count' => false,
                'disconnect_smoothing_ms' => 3000,
                'timeout' => 5,
                'retries' => 3,
                'retry_delay' => 1,
                'batching' => [],
            ];
            $this->webhooks['filter'] += [
                'channel_name_starts_with' => null,
                'channel_name_ends_with' => null,
            ];
            $this->webhooks['batching'] += [
                'enabled' => false,
                'max_events' => 50,
                'max_delay_ms' => 250,
                'max_payload_bytes' => 262_144,
            ];

            $this->webhooks['subscription_count'] = (bool) $this->webhooks['subscription_count'];
            $this->webhooks['disconnect_smoothing_ms'] = (int) $this->webhooks['disconnect_smoothing_ms'];
            $this->webhooks['timeout'] = (int) $this->webhooks['timeout'];
            $this->webhooks['retries'] = (int) $this->webhooks['retries'];
            $this->webhooks['retry_delay'] = (int) $this->webhooks['retry_delay'];
            $this->webhooks['batching']['enabled'] = (bool) $this->webhooks['batching']['enabled'];
            $this->webhooks['batching']['max_events'] = (int) $this->webhooks['batching']['max_events'];
            $this->webhooks['batching']['max_delay_ms'] = (int) $this->webhooks['batching']['max_delay_ms'];
            $this->webhooks['batching']['max_payload_bytes'] = (int) $this->webhooks['batching']['max_payload_bytes'];
        }
    }

    /**
     * Get the application ID.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the application key.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Get the application secret.
     */
    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * Get the allowed origins.
     *
     * @return array<int, string>
     */
    public function allowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /**
     * Get the client ping interval in seconds.
     */
    public function pingInterval(): int
    {
        return $this->pingInterval;
    }

    /**
     * Get the activity timeout in seconds.
     */
    public function activityTimeout(): int
    {
        return $this->activityTimeout;
    }

    /**
     * Get the maximum connections allowed for the application.
     */
    public function maxConnections(): ?int
    {
        return $this->maxConnections;
    }

    /**
     * Determine if the application has a maximum connection limit.
     */
    public function hasMaxConnectionLimit(): bool
    {
        return $this->maxConnections !== null;
    }

    /**
     * Get the maximum message size allowed from the client.
     */
    public function maxMessageSize(): int
    {
        return $this->maxMessageSize;
    }

    /**
     * Get who client events are accepted from for the application.
     */
    public function acceptClientEventsFrom(): string
    {
        return $this->acceptClientEventsFrom;
    }

    /**
     * Get the rate limiting configuration for the application.
     */
    public function rateLimiting(): ?array
    {
        return $this->rateLimiting;
    }

    /**
     * Determine if the application has rate limiting enabled.
     */
    public function usesRateLimiting(): bool
    {
        return $this->rateLimiting !== null && $this->rateLimiting['enabled'];
    }

    /**
     * Get the application options.
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Get the webhook configuration for the application.
     */
    public function webhooks(): array
    {
        return $this->webhooks;
    }

    /**
     * Determine if the application has webhooks configured.
     */
    public function hasWebhooks(): bool
    {
        if ($this->webhooks === []) {
            return false;
        }

        $url = $this->webhooks['url'];

        return $url !== null && $url !== '';
    }

    /**
     * Convert the application to an array.
     *
     * This is the Pusher client configuration shape, not the complete
     * application constructor or configuration record.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'app_id' => $this->id,
            'key' => $this->key,
            'secret' => $this->secret,
            'ping_interval' => $this->pingInterval,
            'activity_timeout' => $this->activityTimeout,
            'allowed_origins' => $this->allowedOrigins,
            'max_message_size' => $this->maxMessageSize,
            'options' => $this->options,
        ];
    }
}
