<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use GuzzleHttp\Psr7\Message;

class RequestException extends HttpClientException
{
    /**
     * The current truncation length for the exception message.
     */
    public false|int|null $truncateExceptionsAt;

    /**
     * The global truncation length for the exception message.
     */
    public static false|int $truncateAt = 120;

    /**
     * Whether the response has been summarized in the message.
     */
    public bool $hasBeenSummarized = false;

    /**
     * Create a new exception instance.
     */
    public function __construct(
        public Response $response,
        false|int|null $truncateExceptionsAt = null,
    ) {
        parent::__construct($this->prepareMessage($response), $response->status());

        $this->truncateExceptionsAt = $truncateExceptionsAt;
    }

    /**
     * Enable truncation of request exception messages.
     */
    public static function truncate(): void
    {
        static::$truncateAt = 120;
    }

    /**
     * Set the truncation length for request exception messages.
     */
    public static function truncateAt(int $length): void
    {
        static::$truncateAt = $length;
    }

    /**
     * Disable truncation of request exception messages.
     */
    public static function dontTruncate(): void
    {
        static::$truncateAt = false;
    }

    /**
     * Prepare the exception message and set the summarized flag.
     */
    public function report(): bool
    {
        if (! $this->hasBeenSummarized) {
            $this->message = $this->prepareMessage($this->response);

            $this->hasBeenSummarized = true;
        }

        return false;
    }

    /**
     * Prepare the exception message.
     */
    protected function prepareMessage(Response $response): string
    {
        $message = "HTTP request returned status code {$response->status()}";

        $truncateExceptionsAt = $this->truncateExceptionsAt ?? static::$truncateAt;

        $psrResponse = $response->toPsrResponse();

        $summary = null;

        if (is_int($truncateExceptionsAt)) {
            $summary = Message::bodySummary($psrResponse, $truncateExceptionsAt);
        } elseif (($body = $psrResponse->getBody())->isSeekable() && $body->isReadable()) {
            $summary = Message::toString($psrResponse);
        }

        return is_null($summary) ? $message : $message . ":\n{$summary}\n";
    }
}
