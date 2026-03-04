<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server;

interface RequestParserInterface
{
    /**
     * Parse the raw body into an array of parameters.
     */
    public function parse(string $rawBody, string $contentType): array;

    /**
     * Determine if the parser supports the given content type.
     */
    public function has(string $contentType): bool;
}
