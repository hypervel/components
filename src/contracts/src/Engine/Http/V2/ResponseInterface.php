<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface ResponseInterface
{
    /**
     * Get the stream ID.
     */
    public function getStreamId(): int;

    /**
     * Get the response status code.
     */
    public function getStatusCode(): int;

    /**
     * Get the response headers.
     */
    public function getHeaders(): array;

    /**
     * Get the response body.
     */
    public function getBody(): ?string;

    /**
     * Determine whether the response event ends the stream.
     */
    public function isEndStream(): bool;
}
