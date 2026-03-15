<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Client\Events\ConnectionFailed;
use Hypervel\Http\Client\Events\ResponseReceived;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ClientRequestWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(Container $app): void
    {
        $app->make('events')->listen(ConnectionFailed::class, [$this, 'recordFailedRequest']);
        $app->make('events')->listen(ResponseReceived::class, [$this, 'recordResponse']);
    }

    /**
     * Record a HTTP client connection failed request event.
     */
    public function recordFailedRequest(ConnectionFailed $event): void
    {
        if (! Telescope::isRecording()
            || $this->shouldSkipRequest($event->request)) {
            return;
        }

        Telescope::recordClientRequest(
            IncomingEntry::make([
                'method' => $event->request->method(),
                'uri' => $event->request->url(),
                'headers' => $this->headers($event->request->headers()),
                'payload' => $this->payload($this->input($event->request)),
            ])
                ->tags([$event->request->toPsrRequest()->getUri()->getHost()])
        );
    }

    /**
     * Record a HTTP client response.
     */
    public function recordResponse(ResponseReceived $event): void
    {
        if (! Telescope::isRecording()
            || $this->shouldSkipRequest($event->request)
            || $this->shouldIgnoreHost($event)) {
            return;
        }

        Telescope::recordClientRequest(
            IncomingEntry::make([
                'method' => $event->request->method(),
                'uri' => $event->request->url(),
                'headers' => $this->headers($event->request->headers()),
                'payload' => $this->payload($this->input($event->request)),
                'response_status' => $event->response->status(),
                'response_headers' => $this->headers($event->response->headers()),
                'response' => $this->response($event->response),
                'duration' => $this->duration($event->response),
            ])
                ->tags([$event->request->toPsrRequest()->getUri()->getHost()])
        );
    }

    /**
     * Determine if the request has opted out of Telescope recording.
     */
    protected function shouldSkipRequest(Request $request): bool
    {
        return ($request->attributes()['telescope'] ?? null) === false;
    }

    /**
     * Determine whether to ignore this request based on its host.
     */
    protected function shouldIgnoreHost(ResponseReceived $event): bool
    {
        $host = $event->request->toPsrRequest()->getUri()->getHost();

        return in_array($host, Arr::get($this->options, 'ignore_hosts', []));
    }

    /**
     * Determine if the content is within the set limits.
     */
    public function contentWithinLimits(string $content, string $type): bool
    {
        $limit = $this->options[$type] ?? 64;

        return mb_strlen($content) / 1000 <= $limit;
    }

    /**
     * Format the given response object.
     */
    protected function response(Response $response): array|string
    {
        $stream = $response->toPsrResponse()->getBody();

        if (! $stream->isSeekable()) {
            return 'Stream Response';
        }

        $content = $response->body();

        $stream->rewind();

        if (is_array(json_decode($content, true))
            && json_last_error() === JSON_ERROR_NONE) {
            return $this->contentWithinLimits($content, 'response_size_limit')
                ? $this->hideParameters(json_decode($content, true), Telescope::$hiddenResponseParameters)
                : 'Purged By Telescope';
        }

        if (Str::startsWith(strtolower($response->header('Content-Type')), 'text/plain')) {
            return $this->contentWithinLimits($content, 'response_size_limit') ? $content : 'Purged By Telescope';
        }

        if ($response->redirect()) {
            return 'Redirected to ' . $response->header('Location');
        }

        if (empty($content)) {
            return 'Empty Response';
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

    /**
     * Format the given payload.
     */
    protected function payload(array $payload): array|string
    {
        $encoded = json_encode($payload);

        if ($encoded !== false && ! $this->contentWithinLimits($encoded, 'request_size_limit')) {
            return 'Purged By Telescope';
        }

        return $this->hideParameters(
            $payload,
            Telescope::$hiddenRequestParameters
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

    /**
     * Extract the input from the given request.
     */
    protected function input(Request $request): array
    {
        if (! $request->isMultipart()) {
            return $request->data();
        }

        return collect($request->data())->mapWithKeys(function (array $data) {
            if ($data['contents'] instanceof UploadedFile) {
                $value = [
                    'name' => $data['filename'] ?? $data['contents']->getClientOriginalName(),
                    'size' => ($data['contents']->getSize() / 1000) . 'KB',
                    'headers' => $data['headers'] ?? [],
                ];
            } elseif (is_resource($data['contents'])) {
                $filesize = @filesize(stream_get_meta_data($data['contents'])['uri']);

                $value = [
                    'name' => $data['filename'] ?? null,
                    'size' => $filesize ? ($filesize / 1000) . 'KB' : null,
                    'headers' => $data['headers'] ?? [],
                ];
            } elseif (json_encode($data['contents']) === false) {
                $value = [
                    'name' => $data['filename'] ?? null,
                    'size' => (strlen($data['contents']) / 1000) . 'KB',
                    'headers' => $data['headers'] ?? [],
                ];
            } else {
                $value = $data['contents'];
            }

            return [$data['name'] => $value];
        })->toArray();
    }

    /**
     * Get the request duration in milliseconds.
     */
    protected function duration(Response $response): ?float
    {
        if ($response->transferStats
            && $response->transferStats->getTransferTime()) {
            return floor($response->transferStats->getTransferTime() * 1000);
        }

        return null;
    }
}
