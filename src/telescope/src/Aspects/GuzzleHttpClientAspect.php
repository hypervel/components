<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Aspects;

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Stringable\Str;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class GuzzleHttpClientAspect extends AbstractAspect
{
    public array $classes = [
        Client::class . '::transfer',
    ];

    protected array $options = [];

    public function __construct(protected ConfigInterface $config)
    {
        $this->options = $config->get('telescope.aspects.' . static::class, []);
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        // If the guzzle aspect is disabled, we will not record the request.
        if (! Telescope::$started
            || ! ($this->options['enabled'] ?? false)
            || ! Telescope::isRecording()
        ) {
            return $proceedingJoinPoint->process();
        }

        $options = $proceedingJoinPoint->arguments['keys']['options'] ?? [];
        $guzzleConfig = (fn () => $this->config ?? [])->call($proceedingJoinPoint->getInstance());

        // If the telescope_enabled option is set to false, we will not record the request.
        if (($options['telescope_enabled'] ?? null) === false
            || ($guzzleConfig['telescope_enabled'] ?? null) === false
        ) {
            return $proceedingJoinPoint->process();
        }

        // Add or override the on_stats option to record the request duration.
        $onStats = $options['on_stats'] ?? null;
        $proceedingJoinPoint->arguments['keys']['options']['on_stats'] = function (TransferStats $stats) use ($onStats) {
            try {
                $content = $this->getRequest(
                    $request = $stats->getRequest(),
                    $stats
                );

                if ($response = $stats->getResponse()) {
                    $content = array_merge(
                        $content,
                        $this->getResponse($response)
                    );
                }

                Telescope::recordClientRequest(
                    IncomingEntry::make($content)
                        ->tags([$request->getUri()->getHost()])
                );
            } catch (Throwable $e) {
                // We will catch the exception to prevent the request from being interrupted.
            }

            if (is_callable($onStats)) {
                $onStats($stats);
            }
        };

        return $proceedingJoinPoint->process();
    }

    protected function getRequest(RequestInterface $request, TransferStats $stats): array
    {
        return [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => $this->headers($request->getHeaders()),
            'payload' => $this->getRequestPayload($request),
            'duration' => floor($stats->getTransferTime() * 1000),
        ];
    }

    /**
     * Extract the payload from the given request.
     */
    protected function getRequestPayload(RequestInterface $request): array|string
    {
        $stream = $request->getBody();
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $sizeLimit = ($this->options['request_size_limit'] ?? 64) * 1024;
            if ($stream->getSize() >= $sizeLimit) {
                return $stream->read($sizeLimit) . ' (truncated...)';
            }

            $content = $stream->getContents();
            if (is_array($decoded = json_decode($content, true))
                && json_last_error() === JSON_ERROR_NONE
            ) {
                return $this->hideParameters($decoded, Telescope::$hiddenResponseParameters);
            }

            return $content;
        } catch (Throwable $e) {
            return 'Purged By Telescope: ' . $e->getMessage();
        } finally {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        }

        return 'Unknown';
    }

    protected function getResponse(ResponseInterface $response): array
    {
        return [
            'response_status' => $response->getStatusCode(),
            'response_headers' => $response->getHeaders(),
            'response' => $this->getResponsePayload($response),
        ];
    }

    protected function getResponsePayload(ResponseInterface $response): array|string
    {
        $stream = $response->getBody();
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $sizeLimit = ($this->options['response_size_limit'] ?? 64) * 1024;
            if ($stream->getSize() >= $sizeLimit) {
                return $stream->read($sizeLimit) . ' (truncated...)';
            }

            $content = $stream->getContents();
            if (is_array($decoded = json_decode($content, true))
                && json_last_error() === JSON_ERROR_NONE
            ) {
                return $this->hideParameters($decoded, Telescope::$hiddenResponseParameters);
            }
            if (Str::startsWith(strtolower($response->getHeaderLine('content-type') ?: ''), 'text/plain')) {
                return $content;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300 && $statusCode < 400) {
                return 'Redirected to ' . $response->getHeaderLine('Location');
            }

            if (empty($content)) {
                return 'Empty Response';
            }
        } catch (Throwable $e) {
            return 'Purged By Telescope: ' . $e->getMessage();
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
        $headerNames = array_map(function (string $headerName) {
            return strtolower($headerName);
        }, array_keys($headers));

        $headerValues = array_map(function (array $header) {
            return implode(', ', $header);
        }, $headers);

        $headers = array_combine($headerNames, $headerValues);

        return $this->hideParameters(
            $headers,
            Telescope::$hiddenRequestHeaders
        );
    }

    /**
     * Hide the given parameters.
     */
    protected function hideParameters(array $data, array $hidden): array
    {
        foreach ($hidden as $parameter) {
            if (Arr::get($data, $parameter)) {
                Arr::set($data, $parameter, '********');
            }
        }

        return $data;
    }
}
