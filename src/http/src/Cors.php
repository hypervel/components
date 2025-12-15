<?php

/*
 * This file ported from fruitcake/php-cors
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Hypervel\Http;

use Hyperf\Context\Context;
use Hypervel\Context\ApplicationContext;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Contracts\ResponseContract;
use Psr\Http\Message\ResponseInterface;

/**
 * CORS service with coroutine-safe options storage.
 *
 * Options are stored in coroutine-local Context as an immutable CorsOptions
 * object, making this service safe for concurrent use in Swoole's coroutine
 * environment. Each request gets its own isolated CORS configuration.
 *
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
    /**
     * Context key for storing CORS options.
     */
    private const CONTEXT_KEY = '__cors.options';

    /**
     * @param CorsInputOptions $options
     */
    public function __construct(array $options = [])
    {
        if ($options !== []) {
            $this->setOptions($options);
        }
    }

    /**
     * Set CORS options for the current request.
     *
     * Options are normalized and stored in coroutine-local Context as an
     * immutable CorsOptions object, ensuring isolation between concurrent
     * requests. Type validation is handled by CorsOptions' typed properties.
     *
     * @param CorsInputOptions $options
     */
    public function setOptions(array $options): void
    {
        $current = $this->getOptions();

        $allowedOrigins = $options['allowedOrigins'] ?? $options['allowed_origins'] ?? $current->allowedOrigins;
        $allowedOriginsPatterns = $options['allowedOriginsPatterns'] ?? $options['allowed_origins_patterns'] ?? $current->allowedOriginsPatterns;
        $allowedMethods = $options['allowedMethods'] ?? $options['allowed_methods'] ?? $current->allowedMethods;
        $allowedHeaders = $options['allowedHeaders'] ?? $options['allowed_headers'] ?? $current->allowedHeaders;
        $supportsCredentials = $options['supportsCredentials'] ?? $options['supports_credentials'] ?? $current->supportsCredentials;

        $maxAge = $current->maxAge;
        if (array_key_exists('maxAge', $options)) {
            $maxAge = $options['maxAge'];
        } elseif (array_key_exists('max_age', $options)) {
            $maxAge = $options['max_age'];
        }
        $maxAge = $maxAge === null ? null : (int) $maxAge;

        $exposedHeaders = $options['exposedHeaders'] ?? $options['exposed_headers'] ?? $current->exposedHeaders;
        $exposedHeaders = $exposedHeaders === false ? [] : $exposedHeaders;

        // Normalize case
        $allowedHeaders = array_map('strtolower', $allowedHeaders);
        $allowedMethods = array_map('strtoupper', $allowedMethods);

        // Normalize ['*'] to flags
        $allowAllOrigins = in_array('*', $allowedOrigins);
        $allowAllHeaders = in_array('*', $allowedHeaders);
        $allowAllMethods = in_array('*', $allowedMethods);

        // Transform wildcard patterns in origins
        if (! $allowAllOrigins) {
            foreach ($allowedOrigins as $origin) {
                if (strpos($origin, '*') !== false) {
                    $allowedOriginsPatterns[] = $this->convertWildcardToPattern($origin);
                }
            }
        }

        Context::set(self::CONTEXT_KEY, new CorsOptions(
            allowedOrigins: $allowedOrigins,
            allowedOriginsPatterns: $allowedOriginsPatterns,
            supportsCredentials: $supportsCredentials,
            allowedHeaders: $allowedHeaders,
            allowedMethods: $allowedMethods,
            exposedHeaders: $exposedHeaders,
            maxAge: $maxAge,
            allowAllOrigins: $allowAllOrigins,
            allowAllHeaders: $allowAllHeaders,
            allowAllMethods: $allowAllMethods,
        ));
    }

    /**
     * Get the current CORS options from Context.
     */
    private function getOptions(): CorsOptions
    {
        return Context::get(self::CONTEXT_KEY) ?? new CorsOptions();
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
        $response = ApplicationContext::getContainer()
            ->get(ResponseContract::class)
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
        $options = $this->getOptions();

        if ($options->allowAllOrigins) {
            return true;
        }

        $origin = $request->header('Origin') ?: '';

        if (in_array($origin, $options->allowedOrigins)) {
            return true;
        }

        foreach ($options->allowedOriginsPatterns as $pattern) {
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
        $options = $this->getOptions();

        if ($options->allowAllOrigins && ! $options->supportsCredentials) {
            // Safe+cacheable, allow everything
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        } elseif ($this->isSingleOriginAllowed()) {
            // Single origins can be safely set
            $response = $response->withHeader('Access-Control-Allow-Origin', array_values($options->allowedOrigins)[0]);
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
        $options = $this->getOptions();

        if ($options->allowAllOrigins || count($options->allowedOriginsPatterns) > 0) {
            return false;
        }

        return count($options->allowedOrigins) === 1;
    }

    private function configureAllowedMethods(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        $options = $this->getOptions();

        if ($options->allowAllMethods) {
            $allowMethods = strtoupper($request->header('Access-Control-Request-Method'));
            $response = $this->varyHeader($response, 'Access-Control-Request-Method');
        } else {
            $allowMethods = implode(', ', $options->allowedMethods);
        }

        return $response->withHeader('Access-Control-Allow-Methods', $allowMethods);
    }

    private function configureAllowedHeaders(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        $options = $this->getOptions();

        if ($options->allowAllHeaders) {
            $allowHeaders = $request->header('Access-Control-Request-Headers');
            $this->varyHeader($response, 'Access-Control-Request-Headers');
        } else {
            $allowHeaders = implode(', ', $options->allowedHeaders);
        }

        return $response->withHeader('Access-Control-Allow-Headers', $allowHeaders);
    }

    private function configureAllowCredentials(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        $options = $this->getOptions();

        if ($options->supportsCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function configureExposedHeaders(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        $options = $this->getOptions();

        if ($options->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $options->exposedHeaders));
        }

        return $response;
    }

    private function configureMaxAge(ResponseInterface $response, RequestContract $request): ResponseInterface
    {
        $options = $this->getOptions();

        if ($options->maxAge !== null) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $options->maxAge);
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
                if (count($varyHeaders) === 1) {
                    $response = $response->withHeader('Vary', ((string) $varyHeaders[0]) . ', ' . $header);
                } else {
                    $response->withHeader($header, false);
                }
            }
        }

        return $response;
    }
}
