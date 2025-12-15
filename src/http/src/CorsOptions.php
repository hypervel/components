<?php

declare(strict_types=1);

namespace Hypervel\Http;

/**
 * Immutable value object for normalized CORS options.
 *
 * Used for coroutine-safe storage in Context. Type validation is handled
 * automatically by PHP's type system via typed properties - passing invalid
 * types to the constructor will throw a TypeError.
 */
final readonly class CorsOptions
{
    /**
     * @param string[] $allowedOrigins Specific origins allowed to make requests
     * @param string[] $allowedOriginsPatterns Regex patterns for matching allowed origins
     * @param bool $supportsCredentials Whether to allow credentials (cookies, auth headers)
     * @param string[] $allowedHeaders Headers the client is allowed to send
     * @param string[] $allowedMethods HTTP methods the client is allowed to use
     * @param string[] $exposedHeaders Headers the client is allowed to read from response
     * @param null|int $maxAge How long preflight results can be cached (null = don't send header)
     * @param bool $allowAllOrigins Whether '*' was specified for origins
     * @param bool $allowAllHeaders Whether '*' was specified for headers
     * @param bool $allowAllMethods Whether '*' was specified for methods
     */
    public function __construct(
        public array $allowedOrigins = [],
        public array $allowedOriginsPatterns = [],
        public bool $supportsCredentials = false,
        public array $allowedHeaders = [],
        public array $allowedMethods = [],
        public array $exposedHeaders = [],
        public ?int $maxAge = 0,
        public bool $allowAllOrigins = false,
        public bool $allowAllHeaders = false,
        public bool $allowAllMethods = false,
    ) {
    }
}
