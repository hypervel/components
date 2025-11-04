<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Event\RequestHandled;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Server as HttpServer;
use Hyperf\Server\Event;
use Hyperf\Stringable\Str;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class RequestWatcher extends Watcher
{
    /**
     * The request methods that should be ignored.
     */
    protected ?array $ignoreHttpMethods = null;

    /**
     * The container instance.
     */
    protected ?RequestContract $request = null;

    /**
     * The entries repository.
     */
    protected ?EntriesRepository $entriesRepository = null;

    /**
     * The request options.
     */
    protected array $requestOptions = [];

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $this->request = $app->get(RequestContract::class);
        $this->entriesRepository = $app->get(EntriesRepository::class);

        $this->enableRequestEvents($app);

        $app->get(EventDispatcherInterface::class)
            ->listen(RequestHandled::class, [$this, 'recordRequest']);
    }

    protected function enableRequestEvents(ContainerInterface $app): void
    {
        $config = $app->get(ConfigInterface::class);
        $servers = $config->get('server.servers', []);

        foreach ($servers as &$server) {
            $callbacks = $server['callbacks'] ?? [];

            if (! ($handler = $callbacks[Event::ON_REQUEST][0] ?? null)) {
                continue;
            }

            if (is_a($handler, HttpServer::class, true)) {
                $server['options'] ??= [];
                $server['options']['enable_request_lifecycle'] = true;
            }
        }

        $config->set('server.servers', $servers);
    }

    /**
     * Record an incoming HTTP request.
     */
    public function recordRequest(RequestHandled $event): void
    {
        if (! Telescope::isRecording()
            || $this->shouldIgnoreHttpMethod($event->request)
            || $this->shouldIgnoreStatusCode($event->response)
        ) {
            return;
        }

        $startTime = $event->request->getServerParams()['request_time_float'];

        /** @var Dispatched $dispatched */
        $dispatched = $event->request->getAttribute(Dispatched::class);
        Telescope::recordRequest(IncomingEntry::make([
            'ip_address' => $this->request->ip(),
            'uri' => str_replace($this->request->root(), '', $this->request->fullUrl()) ?: '/',
            'method' => $this->request->method(),
            'controller_action' => $dispatched->handler ? $dispatched->handler->callback : '',
            'middleware' => Context::get('request.middleware', []),
            'headers' => $this->headers($this->request->getHeaders()),
            'payload' => $this->payload($this->input()),
            'session' => $this->payload($this->sessionVariables()),
            'response_headers' => $this->headers($event->response->getHeaders()),
            'response_status' => $event->response->getStatusCode(),
            'response' => $this->response($event->response),
            'context' => $this->getContext(),
            'duration' => $startTime ? floor((microtime(true) - $startTime) * 1000) : null,
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ]));

        Telescope::store($this->entriesRepository);
        Telescope::stopRecording();
    }

    /**
     * Determine if the request should be ignored based on its method.
     */
    protected function shouldIgnoreHttpMethod(ServerRequestInterface $request): bool
    {
        $ignoreHttpMethods = is_null($this->ignoreHttpMethods)
            ? $this->ignoreHttpMethods = Collection::make($this->options['ignore_http_methods'] ?? [])->map(function ($method) {
                return strtolower($method);
            })->all()
            : $this->ignoreHttpMethods;

        return in_array(
            strtolower($request->getMethod()),
            $ignoreHttpMethods
        );
    }

    /**
     * Determine if the request should be ignored based on its status code.
     */
    protected function shouldIgnoreStatusCode(ResponseInterface $response): bool
    {
        return in_array(
            $response->getStatusCode(),
            $this->options['ignore_status_codes'] ?? []
        );
    }

    /**
     * Format the given headers.
     */
    protected function headers(array $headers): array
    {
        $headers = Collection::make($headers)
            ->map(fn ($header) => implode(', ', $header))
            ->all();

        return $this->hideParameters(
            $headers,
            Telescope::$hiddenRequestHeaders
        );
    }

    /**
     * Format the given payload.
     */
    protected function payload(array $payload): array
    {
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
     * Extract the session variables from the given request.
     */
    private function sessionVariables(): array
    {
        return $this->request->hasSession()
            ? $this->request->session()->all()
            : [];
    }

    /**
     * Extract the input from the given request.
     */
    private function input(): array
    {
        $files = $this->request->getUploadedFiles();

        array_walk_recursive($files, static function (&$file) {
            $file = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->isFile() ? ($file->getSize() / 1000) . 'KB' : '0',
            ];
        });

        return array_replace_recursive($this->request->all(), $files);
    }

    /**
     * Format the given response object.
     */
    protected function response(ResponseInterface $response): array|string
    {
        $stream = $response->getBody();

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $content = $stream->getContents();
        } catch (Throwable $e) {
            return 'Purged By Telescope: ' . $e->getMessage();
        }

        if (is_string($content)) {
            if (! $this->contentWithinLimits($content)) {
                return 'Purged By Telescope';
            }
            if (is_array(json_decode($content, true))
                && json_last_error() === JSON_ERROR_NONE
            ) {
                return $this->hideParameters(json_decode($content, true), Telescope::$hiddenResponseParameters);
            }
            if (Str::startsWith(strtolower($response->getHeaderLine('Content-Type') ?: ''), 'text/plain')) {
                return $content;
            }
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 300 && $statusCode < 400) {
            return 'Redirected to ' . $response->getHeaderLine('Location');
        }

        if (empty($content)) {
            return 'Empty Response';
        }

        return 'HTML Response';
    }

    /**
     * Determine if the content is within the set limits.
     */
    public function contentWithinLimits(string $content): bool
    {
        $limit = $this->options['size_limit'] ?? 64;

        return intdiv(mb_strlen($content), 1000) <= $limit;
    }

    /**
     * Get the context data for the request.
     */
    protected function getContext(): array
    {
        $result = [];
        foreach (Context::getContainer() as $key => $value) {
            if ($key === 'di.depth') {
                continue;
            }
            if (is_object($value)) {
                $value = 'object(' . get_class($value) . ')';
            } elseif (is_array($value)) {
                $value = 'array(' . count($value) . ')';
            } elseif (is_string($value)) {
                $value = $this->contentWithinLimits($value);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
