<?php

/*
 * This file ported from fruitcake/php-cors
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Container\Container;
use Hypervel\Contracts\Http\Request as RequestContract;
use Hypervel\Contracts\Http\Response as ResponseContract;
use Psr\Http\Message\ResponseInterface;

/**
 * @phpstan-type CorsInputOptions array{
 *  'allowedOrigins'?: string[],
 *  'allowedOriginsPatterns'?: string[],
 *  'supportsCredentials'?: bool,
 *  'allowedHeaders'?: string[],
 *  'allowedMethods'?: string[],
 *  'exposedHeaders'?: string[]|false,
 *  'maxAge'?: int|bool|null,
 *  'allowed_origins'?: string[],
 *  'allowed_origins_patterns'?: string[],
 *  'supports_credentials'?: bool,
 *  'allowed_headers'?: string[],
 *  'allowed_methods'?: string[],
 *  'exposed_headers'?: string[]|false,
 *  'max_age'?: int|bool|null
 * }
 */
class Cors
{
    /** @var string[] */
    private array $allowedOrigins = [];

    /** @var string[] */
    private array $allowedOriginsPatterns = [];

    /** @var string[] */
    private array $allowedMethods = [];

    /** @var string[] */
    private array $allowedHeaders = [];

    /** @var string[] */
    private array $exposedHeaders = [];

    private bool $supportsCredentials = false;

    private ?int $maxAge = 0;

    private bool $allowAllOrigins = false;

    private bool $allowAllMethods = false;

    private bool $allowAllHeaders = false;

    /**
     * @param CorsInputOptions $options
     */
    public function __construct(array $options = [])
    {
        if ($options) {
            $this->setOptions($options);
        }
    }

    /**
     * @param CorsInputOptions $options
     */
    public function setOptions(array $options): void
    {
        $this->allowedOrigins = $options['allowedOrigins'] ?? $options['allowed_origins'] ?? $this->allowedOrigins;
        $this->allowedOriginsPatterns
            = $options['allowedOriginsPatterns'] ?? $options['allowed_origins_patterns'] ?? $this->allowedOriginsPatterns;
        $this->allowedMethods = $options['allowedMethods'] ?? $options['allowed_methods'] ?? $this->allowedMethods;
        $this->allowedHeaders = $options['allowedHeaders'] ?? $options['allowed_headers'] ?? $this->allowedHeaders;
        $this->supportsCredentials
            = $options['supportsCredentials'] ?? $options['supports_credentials'] ?? $this->supportsCredentials;

        $maxAge = $this->maxAge;
        if (array_key_exists('maxAge', $options)) {
            $maxAge = $options['maxAge'];
        } elseif (array_key_exists('max_age', $options)) {
            $maxAge = $options['max_age'];
        }
        $this->maxAge = $maxAge === null ? null : (int) $maxAge;

        $exposedHeaders = $options['exposedHeaders'] ?? $options['exposed_headers'] ?? $this->exposedHeaders;
        $this->exposedHeaders = $exposedHeaders === false ? [] : $exposedHeaders;

        $this->normalizeOptions();
    }

    private function normalizeOptions(): void
    {
        // Normalize case
        $this->allowedHeaders = array_map('strtolower', $this->allowedHeaders);
        $this->allowedMethods = array_map('strtoupper', $this->allowedMethods);

        // Normalize ['*'] to true
        $this->allowAllOrigins = in_array('*', $this->allowedOrigins);
        $this->allowAllHeaders = in_array('*', $this->allowedHeaders);
        $this->allowAllMethods = in_array('*', $this->allowedMethods);

        // Transform wildcard pattern
        if (! $this->allowAllOrigins) {
            foreach ($this->allowedOrigins as $origin) {
                if (strpos($origin, '*') !== false) {
                    $this->allowedOriginsPatterns[] = $this->convertWildcardToPattern($origin);
                }
            }
        }
    }

    /**
     * Create a pattern for a wildcard.
     */
    private function convertWildcardToPattern(string $pattern): string
    {
        $pattern = preg_quote($pattern, '#');

        // Asterisks are translated into zero-or-more regular expression wildcards
        // to make it convenient to check if the strings starts with the given
        // pattern such as "*.example.com", making any string check convenient.
        $pattern = str_replace('\*', '.*', $pattern);

        return '#^' . $pattern . '\z#u';
    }

    public function isCorsRequest(RequestContract $request): bool
    {
        return $request->hasHeader('Origin');
    }

    public function isPreflightRequest(RequestContract $request): bool
    {
        return $request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method');
    }

    public function handlePreflightRequest(RequestContract $request): ResponseInterface
    {
        $response = Container::getInstance()
            ->make(ResponseContract::class)
            ->make(status: 204);

        return $this->addPreflightRequestHeaders($response, $request);
    }

    public function addPreflightRequestHeaders(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        $response = $this->configureAllowedOrigin($response, $request);

        if ($response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $this->configureAllowCredentials($response, $request);

            $response = $this->configureAllowedMethods($response, $request);

            $response = $this->configureAllowedHeaders($response, $request);

            $response = $this->configureMaxAge($response, $request);
        }

        return $response;
    }

    public function isOriginAllowed(RequestContract $request): bool
    {
        if ($this->allowAllOrigins === true) {
            return true;
        }

        $origin = $request->header('Origin') ?: '';

        if (in_array($origin, $this->allowedOrigins)) {
            return true;
        }

        foreach ($this->allowedOriginsPatterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    public function addActualRequestHeaders(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        $response = $this->configureAllowedOrigin($response, $request);
        if ($response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $this->configureAllowCredentials($response, $request);

            $response = $this->configureExposedHeaders($response, $request);
        }

        return $response;
    }

    private function configureAllowedOrigin(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        if ($this->allowAllOrigins === true && ! $this->supportsCredentials) {
            // Safe+cacheable, allow everything
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        } elseif ($this->isSingleOriginAllowed()) {
            // Single origins can be safely set
            $response = $response->withHeader('Access-Control-Allow-Origin', array_values($this->allowedOrigins)[0]);
        } else {
            // For dynamic headers, set the requested Origin header when set and allowed
            if ($this->isCorsRequest($request) && $this->isOriginAllowed($request)) {
                $response = $response->withHeader('Access-Control-Allow-Origin', $request->header('Origin'));
            }

            $response = $this->varyHeader($response, 'Origin');
        }

        return $response;
    }

    private function isSingleOriginAllowed(): bool
    {
        if ($this->allowAllOrigins === true || count($this->allowedOriginsPatterns) > 0) {
            return false;
        }

        return count($this->allowedOrigins) === 1;
    }

    private function configureAllowedMethods(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        if ($this->allowAllMethods === true) {
            $allowMethods = strtoupper($request->header('Access-Control-Request-Method'));
            $response = $this->varyHeader($response, 'Access-Control-Request-Method');
        } else {
            $allowMethods = implode(', ', $this->allowedMethods);
        }

        return $response->withHeader('Access-Control-Allow-Methods', $allowMethods);
    }

    private function configureAllowedHeaders(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        if ($this->allowAllHeaders === true) {
            $allowHeaders = $request->header('Access-Control-Request-Headers');
            $this->varyHeader($response, 'Access-Control-Request-Headers');
        } else {
            $allowHeaders = implode(', ', $this->allowedHeaders);
        }

        return $response->withHeader('Access-Control-Allow-Headers', $allowHeaders);
    }

    private function configureAllowCredentials(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        if ($this->supportsCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function configureExposedHeaders(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        if ($this->exposedHeaders) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        return $response;
    }

    private function configureMaxAge(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        if ($this->maxAge !== null) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
        }

        return $response;
    }

    public function varyHeader(ResponseInterface $response, string $header): ResponseInterface
    {
        if (! $response->hasHeader('Vary')) {
            $response = $response->withHeader('Vary', $header);
        } else {
            $varyHeaders = $response->getHeader('Vary');
            if (! in_array($header, $varyHeaders, true)) {
                $response = $response->withHeader('Vary', implode(', ', $varyHeaders) . ', ' . $header);
            }
        }

        return $response;
    }
}
