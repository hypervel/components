<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface RawResponseInterface
{
    /**
     * Get the response status code.
     */
    public function getStatusCode(): int;

    /**
     * @return string[][]
     */
    public function getHeaders(): array;

    /**
     * Get the response body.
     */
    public function getBody(): string;

    /**
     * Get the HTTP protocol version.
     */
    public function getVersion(): string;
}
