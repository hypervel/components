<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Http\Client\RequestException;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Reverb\Webhooks\Events\WebhookFailed;
use Hypervel\Reverb\Webhooks\WebhookPayload;
use Hypervel\Support\Facades\Http;
use Throwable;

class WebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff;

    /**
     * The HTTP request timeout in seconds.
     */
    protected int $httpTimeout;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public WebhookPayload $payload,
        public string $url,
        public string $appKey,
        public string $appSecret,
        int $retries = 3,
        int $retryDelay = 1,
        int $timeout = 5,
        public array $headers = [],
    ) {
        $this->connection = 'redis';
        $this->queue = 'reverb-webhooks';
        // The queue treats zero tries as unlimited, and the first attempt is not a retry.
        $this->tries = $retries + 1;
        $this->backoff = $retryDelay;
        $this->httpTimeout = $timeout;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $body = $this->payload->toJson();
        $signature = hash_hmac('sha256', $body, $this->appSecret);

        $protected = ['x-pusher-key', 'x-pusher-signature', 'content-type'];
        $safeHeaders = array_filter(
            $this->headers,
            fn ($value, $key) => ! in_array(strtolower($key), $protected, true),
            ARRAY_FILTER_USE_BOTH
        );

        // A followed 301, 302 or 303 turns the POST into a body-less GET, so the
        // webhook counts as delivered only when the endpoint itself returns 2xx.
        $response = Http::timeout($this->httpTimeout)
            ->withoutRedirecting()
            ->withHeaders(array_merge(
                $safeHeaders,
                [
                    'X-Pusher-Key' => $this->appKey,
                    'X-Pusher-Signature' => $signature,
                ]
            ))
            ->withBody($body, 'application/json')
            ->post($this->url);

        if (! $response->successful()) {
            throw new RequestException($response);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        if (app('events')->hasListeners(WebhookFailed::class)) {
            WebhookFailed::dispatch($this->payload, $this->url, $exception);
        }
    }
}
