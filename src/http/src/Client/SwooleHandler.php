<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\MultipartStream;
use Hypervel\Contracts\Engine\Http\ClientInterface as EngineClientInterface;
use Hypervel\Engine\Coroutine;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class SwooleHandler
{
    private const PREPARED_OPTION = 'prepared';

    private const SUPPORTED_OPTION = 'supported';

    private const FALLBACK_OPTION = 'fallback';

    private const COMMON_REQUEST_OPTIONS = [
        'allow_redirects' => self::PREPARED_OPTION,
        'auth' => self::SUPPORTED_OPTION,
        'body' => self::PREPARED_OPTION,
        'cert' => self::SUPPORTED_OPTION,
        'cert_type' => self::SUPPORTED_OPTION,
        'cookies' => self::PREPARED_OPTION,
        'connect_timeout' => self::SUPPORTED_OPTION,
        'crypto_method' => self::SUPPORTED_OPTION,
        'crypto_method_max' => self::SUPPORTED_OPTION,
        'curl' => self::FALLBACK_OPTION,
        'debug' => self::FALLBACK_OPTION,
        'decode_content' => self::SUPPORTED_OPTION,
        'delay' => self::SUPPORTED_OPTION,
        'expect' => self::PREPARED_OPTION,
        'form_params' => self::PREPARED_OPTION,
        'headers' => self::PREPARED_OPTION,
        'http_errors' => self::PREPARED_OPTION,
        'idn_conversion' => self::PREPARED_OPTION,
        'json' => self::PREPARED_OPTION,
        'multipart' => self::PREPARED_OPTION,
        'multiplex' => self::SUPPORTED_OPTION,
        'on_headers' => self::FALLBACK_OPTION,
        'on_stats' => self::SUPPORTED_OPTION,
        'on_trailers' => self::FALLBACK_OPTION,
        'progress' => self::FALLBACK_OPTION,
        'protocols' => self::SUPPORTED_OPTION,
        'proxy' => self::FALLBACK_OPTION,
        'query' => self::PREPARED_OPTION,
        'sink' => self::FALLBACK_OPTION,
        'synchronous' => self::SUPPORTED_OPTION,
        'ssl_key' => self::SUPPORTED_OPTION,
        'ssl_key_type' => self::SUPPORTED_OPTION,
        'stream' => self::FALLBACK_OPTION,
        'stream_context' => self::FALLBACK_OPTION,
        'verify' => self::SUPPORTED_OPTION,
        'timeout' => self::SUPPORTED_OPTION,
        'read_timeout' => self::SUPPORTED_OPTION,
        'retries' => self::SUPPORTED_OPTION,
        'version' => self::PREPARED_OPTION,
        'force_ip_resolve' => self::FALLBACK_OPTION,
    ];

    // @TODO: Remove Guzzle 7 compatibility when Hypervel requires Guzzle 8; merge these options into the common map.
    private const GUZZLE_8_REQUEST_OPTIONS = [
        'request_factory' => self::PREPARED_OPTION,
        'stream_factory' => self::SUPPORTED_OPTION,
        'response_factory' => self::SUPPORTED_OPTION,
        'uri_factory' => self::PREPARED_OPTION,
    ];

    private const INTERNAL_OPTIONS = [
        'handler',
        'hypervel_data',
        'no_sentry_aspect',
        'telescope_enabled',
        'telescope_tags',
    ];

    private const PHP_TLS_VERSIONS = [
        STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT => 10,
        STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT => 11,
        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT => 12,
        STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT => 13,
    ];

    private const SWOOLE_TLS_VERSIONS = [
        10 => SWOOLE_SSL_TLSv1,
        11 => SWOOLE_SSL_TLSv1_1,
        12 => SWOOLE_SSL_TLSv1_2,
        13 => SWOOLE_SSL_TLSv1_3,
    ];

    private HttpFactory $httpFactory;

    /**
     * Create a Swoole handler.
     */
    public function __construct()
    {
        $this->httpFactory = new HttpFactory;
    }

    /**
     * Prepare a request for native execution or describe why Guzzle is required.
     */
    public function prepare(RequestInterface $request, array $options): SwooleRequest|string
    {
        if (($options['synchronous'] ?? null) !== true) {
            return 'asynchronous requests require the Guzzle transport';
        }

        if (Coroutine::id() < 0) {
            return 'the Swoole transport requires a coroutine';
        }

        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());

        if (! in_array($scheme, ['http', 'https'], true)) {
            return "URI scheme [{$scheme}] requires the Guzzle transport";
        }

        if ($uri->getUserInfo() !== '') {
            return 'URI userinfo authentication requires the Guzzle transport';
        }

        $host = strtolower($uri->getHost());

        if ($host === '') {
            return 'requests without a URI host require the Guzzle transport';
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($request->getProtocolVersion() !== '1.1') {
            return "HTTP/{$request->getProtocolVersion()} requires the Guzzle transport";
        }

        foreach ($options as $name => $value) {
            if (! is_string($name)) {
                return 'numeric request options require the Guzzle transport';
            }

            $category = $this->optionCategory($name);

            if ($category === null) {
                return "request option [{$name}] is unknown to the Swoole transport";
            }

            if ($category === self::FALLBACK_OPTION && $this->optionIsActive($value)) {
                return "request option [{$name}] requires the Guzzle transport";
            }
        }

        if (($options['auth'] ?? null) !== null && ! $this->supportsAuth($options['auth'])) {
            return 'the configured authentication method requires the Guzzle transport';
        }

        if (isset($options['protocols']) && ! is_array($options['protocols'])) {
            return 'the protocols request option must be an array';
        }

        if (isset($options['protocols']) && ! in_array($scheme, $options['protocols'], true)) {
            return "URI scheme [{$scheme}] is not permitted by the request protocols option";
        }

        if (($options['multiplex'] ?? Multiplexing::NONE) !== Multiplexing::NONE) {
            return 'connection multiplexing requires the Guzzle transport';
        }

        if (($options['retries'] ?? 0) !== 0) {
            return 'transport-level retries require the Guzzle transport';
        }

        if ($request->hasHeader('Expect') && trim($request->getHeaderLine('Expect')) !== '') {
            return 'the Expect request header requires the Guzzle transport';
        }

        if (str_contains(strtolower($request->getHeaderLine('Transfer-Encoding')), 'chunked')) {
            return 'chunked request bodies require the Guzzle transport';
        }

        $body = $request->getBody();
        $bodySize = $body->getSize();

        if ($body instanceof MultipartStream) {
            return 'multipart request bodies require the Guzzle transport';
        }

        if ($bodySize !== 0) {
            if ($bodySize === null || ! $body->isSeekable()) {
                return 'non-seekable or unknown-length request bodies require the Guzzle transport';
            }

            $bodyUri = $body->getMetadata('uri');

            if (! is_string($bodyUri)
                || (! str_starts_with($bodyUri, 'php://temp') && $bodyUri !== 'php://memory')) {
                return 'file-backed request bodies require the Guzzle transport';
            }
        }

        $decodeContent = $options['decode_content'] ?? true;

        if (! is_bool($decodeContent) && ! is_string($decodeContent)) {
            return 'the decode_content request option must be a boolean or string';
        }

        $delay = $options['delay'] ?? 0;

        if ((! is_int($delay) && ! is_float($delay)) || ! is_finite((float) $delay) || $delay < 0) {
            return 'the delay request option must be a non-negative finite number of milliseconds';
        }

        $transferSettings = [
            'connect_timeout' => 0.0,
            'timeout' => 0.0,
            'read_timeout' => 0.0,
            'body_decompression' => $decodeContent !== false,
        ];

        foreach (['connect_timeout', 'timeout', 'read_timeout'] as $name) {
            if (! array_key_exists($name, $options)) {
                continue;
            }

            $value = $options[$name];

            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
                return "request option [{$name}] must be a non-negative finite number";
            }

            $transferSettings[$name] = (float) $value;
        }

        $constructionSettings = $this->prepareTlsSettings($scheme, $host, $options);

        if (is_string($constructionSettings)) {
            return $constructionSettings;
        }

        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $path = $request->getRequestTarget();

        return new SwooleRequest(
            host: $host,
            port: $port,
            ssl: $scheme === 'https',
            constructionSettings: $constructionSettings,
            transferSettings: $transferSettings,
            method: $request->getMethod(),
            path: $path === '' ? '/' : $path,
            headers: $request->getHeaders(),
            body: $body,
            version: $request->getProtocolVersion(),
            decodeContent: $decodeContent !== false,
            delayMicroseconds: (int) ($delay * 1000),
        );
    }

    /**
     * Execute a prepared request on a borrowed Engine client.
     */
    public function send(
        EngineClientInterface $client,
        SwooleRequest $request,
        array $options,
    ): ResponseInterface {
        $streamFactory = $options['stream_factory'] ?? $this->httpFactory;
        $responseFactory = $options['response_factory'] ?? $this->httpFactory;

        if (! $streamFactory instanceof StreamFactoryInterface
            || ! $responseFactory instanceof ResponseFactoryInterface) {
            throw new UnsupportedTransportException(
                'The configured PSR-17 response and stream factories are not supported.',
            );
        }

        if ($request->body->isSeekable()) {
            $request->body->rewind();
        }

        $contents = $request->body->getContents();

        $client->set($request->transferSettings);
        $response = $client->request(
            $request->method,
            $request->path,
            $request->headers,
            $contents,
            $request->version,
        );

        $headers = $response->getHeaders();

        if ($request->decodeContent) {
            $headers = $this->rewriteDecodedHeaders($headers);
        }

        $psrResponse = $responseFactory
            ->createResponse($response->getStatusCode())
            ->withProtocolVersion($response->getVersion())
            ->withBody($streamFactory->createStream($response->getBody()));

        foreach ($headers as $name => $values) {
            $psrResponse = $psrResponse->withAddedHeader($name, $values);
        }

        return $psrResponse;
    }

    /**
     * Get the support category for a request option.
     */
    private function optionCategory(string $name): ?string
    {
        if (in_array($name, self::INTERNAL_OPTIONS, true)) {
            return self::PREPARED_OPTION;
        }

        return self::COMMON_REQUEST_OPTIONS[$name]
            ?? (GuzzleClientInterface::MAJOR_VERSION >= 8 ? self::GUZZLE_8_REQUEST_OPTIONS[$name] ?? null : null);
    }

    /**
     * Determine if a fallback-only option has an active value.
     */
    private function optionIsActive(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== [];
    }

    /**
     * Determine if Guzzle has already materialized supported authentication.
     */
    private function supportsAuth(mixed $auth): bool
    {
        if (! is_array($auth)) {
            return false;
        }

        if ($auth === []) {
            return true;
        }

        $type = $auth[2] ?? 'basic';

        return $type === null || (is_string($type) && strtolower($type) === 'basic');
    }

    /**
     * Prepare immutable TLS construction settings.
     *
     * @return array<string, bool|int|string>|string
     */
    private function prepareTlsSettings(string $scheme, string $host, array $options): array|string
    {
        $minimum = null;
        $maximum = null;

        if (array_key_exists('crypto_method', $options)) {
            $cryptoMethod = $options['crypto_method'];
            $minimum = is_int($cryptoMethod) ? self::PHP_TLS_VERSIONS[$cryptoMethod] ?? null : null;

            if ($minimum === null) {
                return 'the crypto_method request option cannot be represented by Swoole';
            }
        }

        if (array_key_exists('crypto_method_max', $options)) {
            $cryptoMethodMaximum = $options['crypto_method_max'];
            $maximum = is_int($cryptoMethodMaximum)
                ? self::PHP_TLS_VERSIONS[$cryptoMethodMaximum] ?? null
                : null;

            if ($maximum === null) {
                return 'the crypto_method_max request option cannot be represented by Swoole';
            }
        }

        if ($minimum !== null && $maximum !== null && $maximum < $minimum) {
            return 'the configured maximum TLS version is lower than the minimum TLS version';
        }

        if ($scheme !== 'https') {
            return [];
        }

        if ($minimum === null) {
            // @TODO: Remove Guzzle 7 compatibility when Hypervel requires Guzzle 8; use its TLS 1.2 minimum directly.
            if (GuzzleClientInterface::MAJOR_VERSION < 8) {
                return 'Guzzle 7 requests without an explicit TLS minimum require the Guzzle transport';
            }

            $minimum = 12;
        }

        $maximum ??= 13;

        if ($maximum < $minimum) {
            return 'the configured maximum TLS version is lower than the effective minimum TLS version';
        }

        $protocols = 0;

        foreach (self::SWOOLE_TLS_VERSIONS as $version => $protocol) {
            if ($version >= $minimum && $version <= $maximum) {
                $protocols |= $protocol;
            }
        }

        $verify = $options['verify'] ?? true;

        if (! is_bool($verify) && ! is_string($verify)) {
            return 'the verify request option must be a boolean or CA bundle path';
        }

        $settings = [
            'ssl_verify_peer' => $verify !== false,
            'ssl_allow_self_signed' => $verify === false,
            'ssl_host_name' => $host,
            // Guzzle's crypto methods are minimum/maximum versions, while Swoole expects an absolute allow-set.
            'ssl_protocols' => $protocols,
        ];

        if (is_string($verify)) {
            $settings['ssl_cafile'] = $verify;
        }

        $certificate = $options['cert'] ?? null;
        $privateKey = $options['ssl_key'] ?? null;

        if (($certificate === null) !== ($privateKey === null)) {
            return 'native client certificates require both cert and ssl_key request options';
        }

        if ($certificate !== null) {
            if (! is_string($certificate) || ! is_string($privateKey)) {
                return 'password-protected client certificates require the Guzzle transport';
            }

            if (! $this->isPemType($options['cert_type'] ?? null)
                || ! $this->isPemType($options['ssl_key_type'] ?? null)) {
                return 'non-PEM client certificates require the Guzzle transport';
            }

            $settings['ssl_cert_file'] = $certificate;
            $settings['ssl_key_file'] = $privateKey;
        }

        return $settings;
    }

    /**
     * Determine if an optional certificate type is PEM.
     */
    private function isPemType(mixed $type): bool
    {
        return $type === null || (is_string($type) && strtoupper($type) === 'PEM');
    }

    /**
     * Rewrite decoded response metadata to match Guzzle's response contract.
     *
     * @param array<string, string[]> $headers
     * @return array<string, string[]>
     */
    private function rewriteDecodedHeaders(array $headers): array
    {
        $normalized = [];

        foreach (array_keys($headers) as $name) {
            $normalized[strtolower($name)] = $name;
        }

        $encodingName = $normalized['content-encoding'] ?? null;

        if ($encodingName === null) {
            return $headers;
        }

        $headers['x-encoded-content-encoding'] = $headers[$encodingName];
        unset($headers[$encodingName]);

        $lengthName = $normalized['content-length'] ?? null;

        if ($lengthName === null) {
            return $headers;
        }

        $headers['x-encoded-content-length'] = $headers[$lengthName];
        unset($headers[$lengthName]);

        return $headers;
    }
}
