<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Closure;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\Destinations\DestinationResolutionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class UntrustedUrl
{
    protected const int MAX_REDIRECTS = 5;

    public const int MAX_KILOBYTES = 32 * 1024;

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
        $kilobytes = config()->get('ai.remote_files.max_size', static::MAX_KILOBYTES);

        if ($kilobytes !== null && (! is_int($kilobytes) || $kilobytes <= 0)) {
            throw new InvalidArgumentException('The remote file maximum size must be a positive integer or null.');
        }

        $limit = $kilobytes === null ? null : $kilobytes * 1024;

        for ($hop = 0; $hop <= static::MAX_REDIRECTS; ++$hop) {
            $uri = new Uri($url);
            $options = [];
            $tooLarge = false;
            $trusted = in_array(rtrim(trim($uri->getHost(), '[]'), '.'), $allowedHosts, true);

            if ($trusted) {
                if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== '') {
                    throw new InvalidArgumentException('Remote file URLs require HTTP or HTTPS without embedded credentials.');
                }
            }

            if ($limit !== null) {
                $body = Utils::streamFor();

                // The transport drops a compressed response's Content-Length before this runs, so only decoded lengths are checked.
                $options['on_headers'] = static function (ResponseInterface $response) use ($limit, &$tooLarge): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                        $tooLarge = true;

                        throw new RuntimeException('The remote file declares a body larger than the size limit.');
                    }
                };

                // Count decoded bytes at the sink, which also bounds compressed and chunked bodies that declare no decoded length.
                $options['sink'] = FnStream::decorate($body, [
                    'write' => static function (string $chunk) use ($body, $limit, &$tooLarge): int {
                        // The sink appends sequentially, so tell() gives its size without a stat call for every chunk.
                        if (strlen($chunk) > $limit - $body->tell()) {
                            $tooLarge = true;

                            // A short write aborts the transfer while preserving transport cleanup.
                            return 0;
                        }

                        return $body->write($chunk);
                    },
                ]);
            }

            $request = Http::withoutRedirecting()->withOptions($options);

            if (! $trusted) {
                try {
                    // Origin pins preserve normal proxy routing; proxies resolving the
                    // target hostname must enforce their own destination rules.
                    $uri = $request->resolveDestination($policy, (string) $uri)->uri;
                } catch (DisallowedDestinationException|DestinationResolutionException $exception) {
                    throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
                }
            }

            try {
                $response = $request->get((string) $uri);
            } catch (Throwable $exception) {
                if ($tooLarge && ! $exception instanceof CanceledException) {
                    throw new InvalidArgumentException("The remote file exceeds the maximum size of {$kilobytes} kilobytes.");
                }

                throw $exception;
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
