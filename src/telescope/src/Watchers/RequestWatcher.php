<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Http\Response as HypervelResponse;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Support\Collection;
use Hypervel\Support\Json;
use Hypervel\Support\Str;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\FormatModel;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\JsonNormalizer;
use Hypervel\Telescope\Telescope;
use Hypervel\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class RequestWatcher extends Watcher
{
    /**
     * The entries repository.
     */
    protected ?EntriesRepository $entriesRepository = null;

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $this->entriesRepository = $app->make(EntriesRepository::class);

        $app->make(Dispatcher::class)
            ->listen(RequestHandled::class, [$this, 'recordRequest']);
    }

    /**
     * Record an incoming HTTP request.
     */
    public function recordRequest(RequestHandled $event): void
    {
        if (! Telescope::isRecording()
            || $this->shouldIgnoreHttpMethod($event)
            || $this->shouldIgnoreStatusCode($event)
        ) {
            return;
        }

        Telescope::recordRequest(IncomingEntry::make([
            'ip_address' => $event->request->ip(),
            'uri' => str_replace($event->request->root(), '', $event->request->fullUrl()) ?: '/',
            'method' => $event->request->method(),
            'controller_action' => $event->request->route()?->getActionName(),
            'middleware' => array_values($event->request->route()?->gatherMiddleware() ?? []),
            'headers' => $this->headers($event->request->headers->all()),
            'payload' => $this->payload($this->input($event->request)),
            'session' => $this->payload($this->sessionVariables($event->request)),
            'response_headers' => $this->headers($event->response->headers->all()),
            'response_status' => $event->response->getStatusCode(),
            'response' => $this->response($event->response),
            'context' => $this->facadeContext(),
            'coroutine_context' => $this->getContext(),
            'duration' => floor($event->request->startedAt()->diffInMilliseconds()),
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ]));

        Telescope::store($this->entriesRepository);
        Telescope::stopRecording();
    }

    /**
     * Determine if the request should be ignored based on its method.
     */
    protected function shouldIgnoreHttpMethod(RequestHandled $event): bool
    {
        return in_array(
            strtolower($event->request->method()),
            Collection::make($this->options['ignore_http_methods'] ?? [])->map(function ($method) {
                return strtolower($method);
            })->all(),
            true,
        );
    }

    /**
     * Determine if the request should be ignored based on its status code.
     */
    protected function shouldIgnoreStatusCode(RequestHandled $event): bool
    {
        return in_array(
            $event->response->getStatusCode(),
            $this->options['ignore_status_codes'] ?? [],
            true,
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
    protected function payload(array|string $payload): array|string
    {
        if (is_string($payload)) {
            return $payload;
        }

        return $this->hideParameters(
            $payload,
            Telescope::$hiddenRequestParameters
        );
    }

    /**
     * Extract the session variables from the given request.
     */
    private function sessionVariables(Request $request): array
    {
        return $request->hasSession() ? $request->session()->all() : [];
    }

    /**
     * Extract the input from the given request.
     */
    private function input(Request $request): array|string
    {
        if (Str::startsWith(strtolower($request->headers->get('Content-Type') ?? ''), 'text/plain')) {
            return (string) $request->getContent();
        }

        $files = $request->files->all();

        array_walk_recursive($files, function (&$file) {
            $file = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->isFile() ? ($file->getSize() / 1000) . 'KB' : '0',
            ];
        });

        return array_replace_recursive($request->input(), $files);
    }

    /**
     * Format the given response object.
     */
    protected function response(Response $response): array|string
    {
        $content = $response->getContent();

        if (is_string($content)) {
            // One container of the storage limit is reserved for the entry-content root.
            $maximumContainers = Json::MAXIMUM_NESTING_DEPTH - 1;
            $decoded = json_decode($content, true, $maximumContainers + 1);
            $jsonError = json_last_error();

            if (is_array($decoded) && $jsonError === JSON_ERROR_NONE) {
                return $this->contentWithinLimits($content)
                    ? $this->hideParameters($decoded, Telescope::$hiddenResponseParameters)
                    : Telescope::PURGED_VALUE;
            }

            if ($jsonError === JSON_ERROR_DEPTH) {
                return Telescope::PURGED_VALUE;
            }

            if (Str::startsWith(strtolower($response->headers->get('Content-Type') ?? ''), 'text/plain')) {
                return $this->contentWithinLimits($content) ? $content : Telescope::PURGED_VALUE;
            }
        }

        if ($response instanceof RedirectResponse) {
            return 'Redirected to ' . $response->getTargetUrl();
        }

        if ($response instanceof HypervelResponse && $response->getOriginalContent() instanceof View) {
            return [
                'view' => $response->getOriginalContent()->getPath(),
                'data' => $this->extractDataFromView($response->getOriginalContent()),
            ];
        }

        if (is_string($content) && empty($content)) {
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

        return strlen($content) <= $limit * 1024;
    }

    /**
     * Extract the data from the given view in array form.
     */
    protected function extractDataFromView(View $view): array
    {
        // Native encoding captures view object state instead of its published representation.
        return Collection::make($view->getData())->map(function ($value) {
            if ($value instanceof Model) {
                return FormatModel::given($value);
            }
            if (is_object($value)) {
                return [
                    'class' => get_class($value),
                    'properties' => method_exists($value, 'formatForTelescope')
                        ? $value->formatForTelescope()
                        : JsonNormalizer::normalize($value),
                ];
            }

            return JsonNormalizer::normalize($value);
        })->toArray();
    }

    /**
     * Get the current facade context for the request.
     *
     * Returns both visible and hidden context. Returns null
     * when no context exists to avoid showing an empty tab.
     */
    protected function facadeContext(): ?array
    {
        if (! ContextRepository::hasInstance()) {
            return null;
        }

        $repository = ContextRepository::getInstance();
        $data = $repository->all();
        $hidden = $repository->allHidden();

        if (! $data && ! $hidden) {
            return null;
        }

        return [
            'data' => $data,
            'hidden' => $hidden,
        ];
    }

    /**
     * Get the coroutine context data for the request.
     */
    protected function getContext(): array
    {
        $result = [];
        foreach (CoroutineContext::getContainer() as $key => $value) {
            if ($key === Container::DEPTH_CONTEXT_KEY) {
                continue;
            }
            if (is_object($value)) {
                $value = 'object(' . get_class($value) . ')';
            } elseif (is_array($value)) {
                $value = 'array(' . count($value) . ')';
            } elseif (is_string($value)) {
                $value = $this->contentWithinLimits($value)
                    ? $value
                    : Telescope::PURGED_VALUE;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
