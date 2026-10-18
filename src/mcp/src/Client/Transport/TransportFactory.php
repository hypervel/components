<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Transport;

use Hypervel\Mcp\Client\Contracts\Transport;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Support\Arr;

class TransportFactory
{
    /**
     * Rebuild a disconnected transport from its serialized configuration.
     *
     * @param array<string, mixed> $recipe
     */
    public static function fromRecipe(array $recipe): Transport
    {
        return match (Arr::get($recipe, 'driver')) {
            'stdio' => self::stdio($recipe),
            'http' => self::http($recipe),
            default => throw new ClientException('Unable to rebuild transport from an unknown recipe.'),
        };
    }

    /**
     * Rebuild a stdio transport.
     *
     * @param array<string, mixed> $recipe
     */
    protected static function stdio(array $recipe): StdioTransport
    {
        $command = Arr::get($recipe, 'command');
        $args = Arr::get($recipe, 'args', []);
        $env = Arr::get($recipe, 'env', []);

        if (! is_string($command) || ! is_array($args) || ! is_array($env)) {
            throw new ClientException('Invalid stdio transport recipe.');
        }

        /** @var array<string, string> $env */
        $transport = new StdioTransport($command, array_values($args), $env);

        self::applyTimeout($transport, $recipe);

        return $transport;
    }

    /**
     * Rebuild an HTTP transport.
     *
     * @param array<string, mixed> $recipe
     */
    protected static function http(array $recipe): HttpTransport
    {
        $url = Arr::get($recipe, 'url');

        if (! is_string($url)) {
            throw new ClientException('Invalid http transport recipe.');
        }

        $transport = new HttpTransport($url);

        $token = Arr::get($recipe, 'token');

        if (is_string($token)) {
            $transport->withToken($token);
        }

        $headers = Arr::get($recipe, 'headers');

        if (is_array($headers)) {
            /** @var array<string, string> $headers */
            $transport->withHeaders($headers);
        }

        self::applyTimeout($transport, $recipe);

        return $transport;
    }

    /**
     * Apply the configured timeout to the rebuilt transport.
     *
     * @param array<string, mixed> $recipe
     */
    protected static function applyTimeout(Transport $transport, array $recipe): void
    {
        $timeout = Arr::get($recipe, 'timeoutSeconds');

        if (is_int($timeout) || is_float($timeout)) {
            $transport->setTimeoutSeconds((float) $timeout);
        }
    }
}
