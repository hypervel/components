<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\TransferStats;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Http\Client\Request;
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
    protected const int DEFAULT_REQUEST_SIZE_LIMIT = 64;

    /**
     * Register the watcher.
     *
     * Interception is handled by GuzzleHttpClientAspect via AOP.
     */
    public function register(Application $app): void
    {
    }

    /**
     * Record a Guzzle request intercepted via AOP.
     */
    public function recordRequest(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        if (! Telescope::$started
            || ! Telescope::hasWatcher(self::class)
            || ! Telescope::isRecording()) {
            return $proceedingJoinPoint->process();
        }

        $options = $proceedingJoinPoint->arguments['keys']['options'];
        $guzzleConfig = (fn () => $this->config ?? [])->call($proceedingJoinPoint->getInstance());

        if (($options['telescope_enabled'] ?? null) === false
            || ($guzzleConfig['telescope_enabled'] ?? null) === false
        ) {
            return $proceedingJoinPoint->process();
        }

        /** @var RequestInterface $request */
        $request = $proceedingJoinPoint->arguments['keys']['request'];
        $host = $request->getUri()->getHost();

        if ($this->shouldIgnoreHost($host)) {
            return $proceedingJoinPoint->process();
        }

        $customTags = array_map(
            fn ($tag) => $tag instanceof UnitEnum ? (string) enum_value($tag) : $tag,
            $options['telescope_tags'] ?? [],
        );
        $recorded = false;

        $onStats = $options['on_stats'] ?? null;
        $proceedingJoinPoint->arguments['keys']['options']['on_stats'] = function (TransferStats $stats) use ($onStats, $options, $customTags, &$recorded): void {
            try {
                $recorded = true;

                $content = $this->buildRequestData(
                    $stats->getRequest(),
                    $stats,
                    $options
                );

                if ($response = $stats->getResponse()) {
                    $content = array_merge(
                        $content,
                        $this->getResponse($response)
                    );
                }

                Telescope::recordClientRequest(
                    IncomingEntry::make($content)
                        ->tags(array_merge(
                            [$stats->getRequest()->getUri()->getHost()],
                            $customTags
                        ))
                );
            } catch (Throwable) {
                // Prevent recording from interrupting the request.
            }

            if (is_callable($onStats)) {
                $onStats($stats);
            }
        };

        $promise = $proceedingJoinPoint->process();

        return $promise->then(null, function ($reason) use ($request, $options, $customTags, &$recorded) {
            if (! $recorded) {
                try {
                    Telescope::recordClientRequest(
                        IncomingEntry::make([
                            'method' => $request->getMethod(),
                            'uri' => (string) $request->getUri(),
                            'headers' => $this->headers($request->getHeaders()),
                            'payload' => $this->buildPayload($request, $options),
                        ])
                            ->tags(array_merge(
                                [$request->getUri()->getHost()],
                                $customTags
                            ))
                    );
                } catch (Throwable) {
                    // Prevent recording from interrupting the request.
                }
            }

            return Create::rejectionFor($reason);
        });
    }

    /**
     * Determine whether to ignore this request based on its host.
     */
    protected function shouldIgnoreHost(string $host): bool
    {
        return in_array($host, $this->options['ignore_hosts'] ?? [], true);
    }

    /**
     * Build the request data array for a completed transfer.
     */
    protected function buildRequestData(RequestInterface $request, TransferStats $stats, array $options): array
    {
        return [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => $this->headers($request->getHeaders()),
            'payload' => $this->buildPayload($request, $options),
            'duration' => floor($stats->getTransferTime() * 1000),
        ];
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
        if (isset($options['hypervel_data'])) {
            return $this->structuredPayload($request, $options['hypervel_data']);
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
                return 'Redirected to ' . $response->getHeaderLine('Location');
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

            if (Str::startsWith(strtolower($response->getHeaderLine('content-type') ?: ''), 'text/plain')) {
                if (strlen($content) >= $sizeLimit) {
                    return $truncate
                        ? substr($content, 0, $sizeLimit) . ' (truncated...)'
                        : Telescope::PURGED_VALUE;
                }

                return $content;
            }

            if (empty($content)) {
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

        return $this->hideParameters(
            $headers,
            Telescope::$hiddenRequestHeaders
        );
    }
}
