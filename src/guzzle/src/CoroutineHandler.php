<?php

declare(strict_types=1);

namespace Hypervel\Guzzle;

use Exception;
use GuzzleHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Hypervel\Engine\Http\Client;
use Hypervel\Engine\Http\RawResponse;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Http handler that uses Swoole Coroutine as a transport layer.
 */
class CoroutineHandler
{
    /**
     * @see Uri::$defaultPorts
     */
    private static array $defaultPorts = [
        'http' => 80,
        'https' => 443,
    ];

    /**
     * Handle the HTTP request.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        $port = $this->getPort($uri);
        $ssl = $uri->getScheme() === 'https';
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if (empty($path)) {
            $path = '/';
        }
        if ($query !== '') {
            $path .= '?' . $query;
        }

        $client = $this->makeClient($host, $port, $ssl);

        // Init Headers
        $headers = $this->initHeaders($request, $options);
        // Init Settings
        $settings = $this->getSettings($request, $options);
        if (! empty($settings)) {
            $client->set($settings);
        }

        $ms = microtime(true);

        try {
            $raw = $client->request($request->getMethod(), $path, $headers, (string) $request->getBody());
        } catch (Exception $exception) {
            $message = sprintf('Failed to connecting to %s port %s, %s', $host, $port, $exception->getMessage());
            $exception = new ConnectException($message, $request, null, [
                'errCode' => $exception->getCode(),
            ]);
            return Create::rejectionFor($exception);
        }

        $response = $this->getResponse($raw, $request, $options, microtime(true) - $ms);

        return new FulfilledPromise($response);
    }

    /**
     * Create a new HTTP client instance.
     */
    protected function makeClient(string $host, int $port, bool $ssl): Client
    {
        return new Client($host, $port, $ssl);
    }

    /**
     * Initialize the request headers.
     */
    protected function initHeaders(RequestInterface $request, array $options): array
    {
        $headers = $request->getHeaders();
        $userInfo = $request->getUri()->getUserInfo();
        if ($userInfo) {
            $headers['Authorization'] = sprintf('Basic %s', base64_encode($userInfo));
        }

        return $this->rewriteHeaders($headers);
    }

    /**
     * Rewrite headers to be compatible with Swoole's HTTP client.
     */
    protected function rewriteHeaders(array $headers): array
    {
        // Content-Length can cause 400 errors in some cases.
        // Expect header is not supported by Swoole's coroutine HTTP client.
        unset($headers['Content-Length'], $headers['Expect']);

        return $headers;
    }

    /**
     * Get the Swoole client settings from request options.
     */
    protected function getSettings(RequestInterface $request, array $options): array
    {
        $settings = [];
        if (isset($options['delay']) && $options['delay'] > 0) {
            usleep(intval($options['delay'] * 1000));
        }

        // SSL certificate verification
        if (isset($options['verify'])) {
            $settings['ssl_verify_peer'] = false;
            if ($options['verify'] !== false) {
                $settings['ssl_verify_peer'] = true;
                $settings['ssl_allow_self_signed'] = true;
                $settings['ssl_host_name'] = $request->getUri()->getHost();
                if (is_string($options['verify'])) {
                    // Throw an error if the file/folder/link path is not valid or doesn't exist.
                    if (! file_exists($options['verify'])) {
                        throw new InvalidArgumentException("SSL CA bundle not found: {$options['verify']}");
                    }
                    // If it's a directory or a link to a directory use CURLOPT_CAPATH.
                    // If not, it's probably a file, or a link to a file, so use CURLOPT_CAINFO.
                    if (is_dir($options['verify'])
                        || (is_link($options['verify']) && is_dir(readlink($options['verify'])))) {
                        $settings['ssl_capath'] = $options['verify'];
                    } else {
                        $settings['ssl_cafile'] = $options['verify'];
                    }
                }
            }
        }

        // Timeout
        if (isset($options['timeout']) && $options['timeout'] > 0) {
            $settings['timeout'] = $options['timeout'];
        }

        // Proxy
        if (! empty($options['proxy'])) {
            $uri = null;
            if (is_array($options['proxy'])) {
                $scheme = $request->getUri()->getScheme();
                if (isset($options['proxy'][$scheme])) {
                    $host = $request->getUri()->getHost();
                    if (! isset($options['proxy']['no']) || ! GuzzleHttp\Utils::isHostInNoProxy($host, $options['proxy']['no'])) {
                        $uri = new Uri($options['proxy'][$scheme]);
                    }
                }
            } else {
                $uri = new Uri($options['proxy']);
            }

            if ($uri) {
                $settings['http_proxy_host'] = $uri->getHost();
                $settings['http_proxy_port'] = $this->getPort($uri);
                if ($uri->getUserInfo()) {
                    [$user, $password] = explode(':', $uri->getUserInfo());
                    $settings['http_proxy_user'] = $user;
                    if (! empty($password)) {
                        $settings['http_proxy_password'] = $password;
                    }
                }
            }
        }

        // SSL KEY
        isset($options['ssl_key']) && $settings['ssl_key_file'] = $options['ssl_key'];
        isset($options['cert']) && $settings['ssl_cert_file'] = $options['cert'];

        // Swoole Setting
        if (isset($options['swoole']) && is_array($options['swoole'])) {
            $settings = array_replace($settings, $options['swoole']);
        }

        return $settings;
    }

    /**
     * Create a PSR-7 response from the raw response.
     */
    protected function getResponse(RawResponse $raw, RequestInterface $request, array $options, float $transferTime): Psr7\Response
    {
        $body = $raw->body;
        $sink = $options['sink'] ?? null;
        if (isset($sink) && (is_string($sink) || is_resource($sink))) {
            $body = $this->createSink($body, $sink);
        }

        $response = new Psr7\Response(
            $raw->statusCode,
            $raw->headers,
            $body
        );

        if ($callback = $options[RequestOptions::ON_STATS] ?? null) {
            $stats = new TransferStats(
                $request,
                $response,
                $transferTime,
                $raw->statusCode,
                []
            );

            $callback($stats);
        }

        return $response;
    }

    /**
     * Create a stream from the response body.
     */
    protected function createStream(string $body): StreamInterface
    {
        return Utils::streamFor($body);
    }

    /**
     * Write the response body to a sink.
     *
     * @param resource|string $stream
     * @return resource
     */
    protected function createSink(string $body, $stream)
    {
        if (is_string($stream)) {
            $stream = fopen($stream, 'w+');
        }
        if ($body !== '') {
            fwrite($stream, $body);
        }

        return $stream;
    }

    /**
     * Get the port from the URI.
     *
     * @throws InvalidArgumentException
     */
    protected function getPort(UriInterface $uri): int
    {
        if ($port = $uri->getPort()) {
            return $port;
        }
        if (isset(self::$defaultPorts[$uri->getScheme()])) {
            return self::$defaultPorts[$uri->getScheme()];
        }
        throw new InvalidArgumentException("Unsupported scheme from the URI {$uri->__toString()}");
    }
}
