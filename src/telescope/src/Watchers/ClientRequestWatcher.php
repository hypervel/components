<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Json;
use Hypervel\Support\Str;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

class ClientRequestWatcher extends Watcher
{
    private const string MIDDLEWARE_NAME = 'telescope';

    protected const int DEFAULT_REQUEST_SIZE_LIMIT = 64;

    /**
     * Register the watcher.
     *
     * Boot-only. The middleware persists for the worker lifetime and affects every Guzzle client.
     */
    public function register(Application $app): void
    {
        Client::pushGlobalMiddleware($this->recordRequests(...), self::MIDDLEWARE_NAME);
    }

    /**
     * Record each completed Guzzle request.
     */
    protected function recordRequests(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (! Telescope::isRecording()
                || ($options['telescope_enabled'] ?? null) === false
                || $this->shouldIgnoreHost($request->getUri()->getHost())
            ) {
                return $handler($request, $options);
            }

            $customTags = array_map(
                fn ($tag) => $tag instanceof UnitEnum ? (string) enum_value($tag) : $tag,
                $options['telescope_tags'] ?? [],
            );

            // Guzzle applies the request options and middleware after this point and calls on_stats once
            // per redirect, so keep the last prepared request and record once when the transfer settles.
            $stats = null;
            $callerOnStats = $options['on_stats'] ?? null;
            $options['on_stats'] = static function (TransferStats $transferStats) use (&$stats, $callerOnStats): void {
                $stats = $transferStats;

                if ($callerOnStats !== null) {
                    $callerOnStats($transferStats);
                }
            };

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $options, $customTags, &$stats): ResponseInterface {
                    $this->recordTransfer($request, $stats, $response, $options, $customTags);

                    return $response;
                },
                function (mixed $reason) use ($request, $options, $customTags, &$stats): PromiseInterface {
                    $this->recordTransfer($request, $stats, $reason instanceof ResponseException ? $reason->getResponse() : null, $options, $customTags);

                    return Create::rejectionFor($reason);
                }
            );
        };
    }

    /**
     * Record a settled transfer.
     *
     * The last transfer stats hold the prepared request of the final redirect, including the
     * request options and middleware changes; the entry request is used when there are none.
     */
    protected function recordTransfer(RequestInterface $request, ?TransferStats $stats, ?ResponseInterface $response, array $options, array $customTags): void
    {
        $request = $stats?->getRequest() ?? $request;
        $content = [
            'method' => $request->getMethod(),
            'uri' => $this->hideQueryParameters((string) $request->getUri()),
            'headers' => $this->headers($request->getHeaders()),
            'payload' => $this->buildPayload($request, $options),
        ];

        if ($response !== null) {
            $content = array_merge($content, $this->getResponse($response));
        }

        if ($stats?->getTransferTime() !== null) {
            $content['duration'] = floor($stats->getTransferTime() * 1000);
        }

        Telescope::recordClientRequest(
            IncomingEntry::make($content)
                ->tags(array_merge([$request->getUri()->getHost()], $customTags))
        );
    }

    /**
     * Determine whether to ignore this request based on its host.
     */
    protected function shouldIgnoreHost(string $host): bool
    {
        return in_array($host, $this->options['ignore_hosts'] ?? [], true);
    }

    /**
     * Build the request payload, using structured Hypervel data when available.
     *
     * When the request originated from Hypervel's HTTP client, the caller's
     * structured payload is available in the `hypervel_data` Guzzle option.
     * Body replacements made later by before-sending callbacks do not rewrite
     * those original options, so this describes caller intent and may differ
     * from bytes replaced later in the handler stack. Raw and third-party
     * traffic is parsed from the PSR-7 body instead.
     */
    protected function buildPayload(RequestInterface $request, array $options): array|string
    {
        if (isset($options[PendingRequest::DATA_OPTION])) {
            return $this->structuredPayload($request, $options[PendingRequest::DATA_OPTION]);
        }

        return $this->getRequestPayload($request);
    }

    /**
     * Format the structured payload from Hypervel's HTTP client.
     */
    protected function structuredPayload(RequestInterface $request, array $data): array|string
    {
        $isMultipart = str_contains(
            strtolower($request->getHeaderLine('content-type')),
            'multipart'
        );

        if ($isMultipart) {
            $data = $this->formatMultipartData($data);
        }

        return $this->payload($data);
    }

    /**
     * Format multipart data for display.
     */
    protected function formatMultipartData(array $data): array
    {
        return collect($data)->mapWithKeys(function (array $part) {
            if ($part['contents'] instanceof UploadedFile) {
                $value = [
                    'name' => $part['filename'] ?? $part['contents']->getClientOriginalName(),
                    'size' => ($part['contents']->getSize() / 1000) . 'KB',
                    'headers' => $part['headers'] ?? [],
                ];
            } elseif (is_resource($part['contents'])) {
                $filesize = @filesize(stream_get_meta_data($part['contents'])['uri']);

                $value = [
                    'name' => $part['filename'] ?? null,
                    'size' => $filesize ? ($filesize / 1000) . 'KB' : null,
                    'headers' => $part['headers'] ?? [],
                ];
            } elseif (json_encode($part['contents']) === false) {
                $value = [
                    'name' => $part['filename'] ?? null,
                    'size' => (strlen($part['contents']) / 1000) . 'KB',
                    'headers' => $part['headers'] ?? [],
                ];
            } else {
                $value = $part['contents'];
            }

            return [$part['name'] => $value];
        })->toArray();
    }

    /**
     * Format the structured payload with size limit and parameter hiding.
     */
    protected function payload(array $payload): array|string
    {
        return $this->formatStructuredPayload(
            $payload,
            Telescope::$hiddenRequestParameters,
            ($this->options['request_size_limit'] ?? self::DEFAULT_REQUEST_SIZE_LIMIT) * 1024,
        );
    }

    /**
     * Format a structured payload for entry storage.
     */
    protected function formatStructuredPayload(array $payload, array $hidden, int $sizeLimit): array|string
    {
        $masked = $this->hideParameters($payload, $hidden);

        // One container of the storage limit is reserved for the entry-content root.
        $maximumContainers = Json::MAXIMUM_NESTING_DEPTH - 1;
        $encoded = json_encode($masked, JSON_INVALID_UTF8_SUBSTITUTE, $maximumContainers);

        if ($encoded === false) {
            return Telescope::PURGED_VALUE;
        }

        if (strlen($encoded) >= $sizeLimit) {
            return ($this->options['truncate_oversized'] ?? false)
                ? substr($encoded, 0, $sizeLimit) . ' (truncated...)'
                : Telescope::PURGED_VALUE;
        }

        return $masked;
    }

    /**
     * Extract the payload from the raw PSR-7 request body.
     */
    protected function getRequestPayload(RequestInterface $request): array|string
    {
        $stream = $request->getBody();
        $truncate = $this->options['truncate_oversized'] ?? false;

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $sizeLimit = ($this->options['request_size_limit'] ?? self::DEFAULT_REQUEST_SIZE_LIMIT) * 1024;

            if (! $truncate && $stream->getSize() >= $sizeLimit) {
                return Telescope::PURGED_VALUE;
            }

            $content = $stream->getContents();
            $contentType = strtolower($request->getHeaderLine('content-type'));
            $maximumContainers = Json::MAXIMUM_NESTING_DEPTH - 1;
            $decoded = json_decode($content, true, $maximumContainers + 1);
            $jsonError = json_last_error();

            if (is_array($decoded) && $jsonError === JSON_ERROR_NONE) {
                return $this->formatStructuredPayload(
                    $decoded,
                    Telescope::$hiddenRequestParameters,
                    $sizeLimit,
                );
            }

            if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($content, $form);

                return $this->formatStructuredPayload(
                    $form,
                    Telescope::$hiddenRequestParameters,
                    $sizeLimit,
                );
            }

            $firstContentByte = $content[strspn($content, " \t\n\r")] ?? null;

            if ($jsonError !== JSON_ERROR_NONE
                && (str_contains($contentType, '/json')
                    || str_contains($contentType, '+json')
                    || $firstContentByte === '{'
                    || $firstContentByte === '[')
            ) {
                return Telescope::PURGED_VALUE;
            }

            if (strlen($content) >= $sizeLimit) {
                return $truncate
                    ? substr($content, 0, $sizeLimit) . ' (truncated...)'
                    : Telescope::PURGED_VALUE;
            }

            return $content;
        } catch (Throwable $e) {
            return Telescope::PURGED_VALUE . ': ' . $e->getMessage();
        } finally {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        }
    }

    /**
     * Extract response data from the PSR-7 response.
     */
    protected function getResponse(ResponseInterface $response): array
    {
        return [
            'response_status' => $response->getStatusCode(),
            'response_headers' => $this->headers($response->getHeaders()),
            'response' => $this->getResponsePayload($response),
        ];
    }

    /**
     * Extract the payload from the PSR-7 response body.
     */
    protected function getResponsePayload(ResponseInterface $response): array|string
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        } else {
            return 'Streamed Response';
        }

        $truncate = $this->options['truncate_oversized'] ?? false;

        try {
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300 && $statusCode < 400) {
                return 'Redirected to ' . $this->hideQueryParameters($response->getHeaderLine('Location'));
            }

            $sizeLimit = ($this->options['response_size_limit'] ?? 64) * 1024;
            $content = $stream->getContents();
            $maximumContainers = Json::MAXIMUM_NESTING_DEPTH - 1;
            $decoded = json_decode($content, true, $maximumContainers + 1);
            $jsonError = json_last_error();

            if (is_array($decoded) && $jsonError === JSON_ERROR_NONE) {
                return $this->formatStructuredPayload(
                    $decoded,
                    Telescope::$hiddenResponseParameters,
                    $sizeLimit,
                );
            }

            if ($jsonError === JSON_ERROR_DEPTH) {
                return Telescope::PURGED_VALUE;
            }

            $contentType = strtolower($response->getHeaderLine('content-type'));

            // Scalar JSON such as 0, false or null is shown as sent instead of being labeled empty or HTML.
            if (Str::startsWith($contentType, 'text/plain')
                || ($jsonError === JSON_ERROR_NONE && Str::contains($contentType, ['/json', '+json']))
            ) {
                if (strlen($content) >= $sizeLimit) {
                    return $truncate
                        ? substr($content, 0, $sizeLimit) . ' (truncated...)'
                        : Telescope::PURGED_VALUE;
                }

                return $content;
            }

            if ($content === '') {
                return 'Empty Response';
            }
        } catch (Throwable $e) {
            return Telescope::PURGED_VALUE . ': ' . $e->getMessage();
        } finally {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        }

        return 'HTML Response';
    }

    /**
     * Format the given headers.
     */
    protected function headers(array $headers): array
    {
        $headerNames = array_map(
            fn (string $headerName) => strtolower($headerName),
            array_keys($headers)
        );

        $headerValues = array_map(
            fn (array $header) => implode(', ', $header),
            $headers
        );

        $headers = array_combine($headerNames, $headerValues);

        foreach (['location', 'referer'] as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = $this->hideQueryParameters($headers[$header]);
            }
        }

        return $this->hideParameters(
            $headers,
            Telescope::$hiddenRequestHeaders
        );
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        Client::removeGlobalMiddleware(self::MIDDLEWARE_NAME);
    }
}
