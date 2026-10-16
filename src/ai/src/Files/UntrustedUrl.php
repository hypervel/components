<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Closure;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Http\Client\Destinations\DestinationResolutionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use InvalidArgumentException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class UntrustedUrl
{
    public const int DEFAULT_MAX_SIZE = 32 * 1024 * 1024;

    protected const int MAX_REDIRECTS = 5;

    // REMOVED: hostname blocklists; the HTTP destination policy checks the resolved addresses.

    protected static ?Closure $resolver = null;

    /**
     * Fetch the URL, validating it and every redirect hop against private and internal addresses.
     *
     * @throws InvalidArgumentException if a destination is blocked, the download exceeds the configured size, or the URL redirects too many times
     */
    public static function fetch(string $url): Response
    {
        $allowedHosts = config()->array('ai.remote_files.allowed_hosts', []);
        $policy = new RemoteFileDestinationPolicy(static::$resolver);
        $maxSize = config()->get('ai.remote_files.max_size', static::DEFAULT_MAX_SIZE);

        if ($maxSize !== null && (! is_int($maxSize) || $maxSize <= 0)) {
            throw new InvalidArgumentException('The remote file maximum size must be a positive integer or null.');
        }

        for ($hop = 0; $hop <= static::MAX_REDIRECTS; ++$hop) {
            $uri = new Uri($url);
            $options = [];
            $tooLarge = null;

            if (in_array(rtrim(trim($uri->getHost(), '[]'), '.'), $allowedHosts, true)) {
                if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== '') {
                    throw new InvalidArgumentException('Remote file URLs require HTTP or HTTPS without embedded credentials.');
                }
            } else {
                ConnectionResolver::releaseIdleConnections();

                try {
                    $destination = $policy->resolve((string) $uri, PendingRequest::DEFAULT_DESTINATION_RESOLUTION_TIMEOUT);
                } catch (DisallowedDestinationException|DestinationResolutionException $exception) {
                    throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
                }

                $uri = $destination->uri;

                if (filter_var(trim($uri->getHost(), '[]'), FILTER_VALIDATE_IP) === false) {
                    $addresses = array_map(
                        static fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
                        $destination->addresses,
                    );

                    // Unnamed requests own their transport. Origin pins preserve normal proxy routing;
                    // proxies resolving the target hostname must enforce their own destination rules.
                    $options['curl'] = [CURLOPT_RESOLVE => [implode(':', [
                        $destination->resolvedHost,
                        $destination->resolvedPort,
                        implode(',', $addresses),
                    ])]];
                }
            }

            if ($maxSize !== null) {
                $buffer = Utils::streamFor();

                // Count decoded bytes at the sink; transfer sizes may describe compressed data.
                $options['sink'] = FnStream::decorate($buffer, [
                    'write' => static function (string $chunk) use ($buffer, $maxSize, &$tooLarge): int {
                        if (strlen($chunk) > $maxSize - $buffer->tell()) {
                            $tooLarge = new InvalidArgumentException("The remote file exceeds the maximum size of [{$maxSize}] bytes.");

                            // A short write aborts the transfer while preserving transport cleanup.
                            return 0;
                        }

                        return $buffer->write($chunk);
                    },
                ]);
            }

            try {
                $response = Http::withoutRedirecting()
                    ->withOptions($options)
                    ->get((string) $uri);
            } catch (Throwable $exception) {
                throw $exception instanceof CanceledException ? $exception : $tooLarge ?? $exception;
            }

            if (! $response->redirect() || blank($response->header('Location'))) {
                return $response;
            }

            $url = (string) UriResolver::resolve($uri, new Uri($response->header('Location')));
        }

        throw new InvalidArgumentException('The remote file URL redirected too many times.');
    }

    /**
     * Resolve hostnames with the given callback, or the system resolver when null.
     *
     * Boot or tests only. The callback persists for the worker lifetime and affects every subsequent fetch.
     *
     * @param null|(Closure(string): list<string>) $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    // REMOVED: validate(), resolve(), and isBlocked(); RemoteFileDestinationPolicy shares the HTTP client's destination validation.

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$resolver = null;
    }
}
