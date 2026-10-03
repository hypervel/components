<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools;

use Hypervel\Http\Request;
use Hypervel\Http\Response as HttpResponse;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\Support\Header;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Str;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Records the Inertia request lifecycle for the devtools extension, holding the per-request
 * collection state and delegating to {@see SourceLocator}, {@see PropClassifier},
 * {@see Collector} and {@see IncomingEntryBuilder}.
 */
class RequestRecorder
{
    protected ?Collector $collector = null;

    /**
     * Stamp the request start time.
     */
    public function requestStarted(Request $request): void
    {
        if (! DevTools::enabled()) {
            return;
        }

        $request->attributes->set(RequestAttribute::START, hrtime(true));
    }

    /**
     * Scan the share() method source to resolve per-key line numbers.
     *
     * @param array<string, mixed> $shared
     */
    public function sharedPropsResolved(object $middleware, array $shared): void
    {
        if (! DevTools::enabledForRequest()) {
            return;
        }

        $reflection = new ReflectionMethod($middleware, 'share');
        $locator = app(SourceLocator::class);
        $fallback = $locator->shareSourceFallback($reflection);

        if ($fallback === null) {
            return;
        }

        foreach (array_keys($shared) as $key) {
            $key = (string) $key;

            $this->setShareSource([$key], $locator->resolveShareSource($reflection, $key) ?? $fallback);
        }
    }

    /**
     * Capture the call site of a share() call and associate it with the given prop keys.
     *
     * @param array<int, array-key> $keys
     */
    public function propsShared(array $keys): void
    {
        if (! DevTools::enabled()) {
            return;
        }

        $locator = app(SourceLocator::class);
        $source = $locator->captureCallerSource();

        if ($source === null) {
            return;
        }

        $state = InertiaState::current();

        foreach ($keys as $key) {
            $key = (string) $key;

            $state->shareSources[$key] = [
                'file' => $source['file'],
                'line' => $locator->findPropKeyLine($source['file'], $source['line'], $key) ?? $source['line'],
            ];
        }
    }

    /**
     * Start collecting the page being rendered.
     */
    public function pageRendering(string $component, Response $response): void
    {
        if (! DevTools::enabled()) {
            return;
        }

        $locator = app(SourceLocator::class);
        $renderSource = $locator->captureCallerSource();
        $collector = new Collector($component, $locator);

        if ($renderSource !== null) {
            $collector->setRenderSource($renderSource['file'], $renderSource['line']);
        }

        $collector->setShareSources(InertiaState::current()->shareSources);

        $this->collector = $collector;
    }

    /**
     * Mark which of the page's props are shared, once shared property providers have been
     * expanded into the props they provide.
     *
     * @param array<array-key, mixed> $shared
     */
    public function sharedPropsExpanded(array $shared): void
    {
        $this->collector?->setSharedKeys($this->topLevelSharedKeys($shared));
    }

    /**
     * Record a resolved prop.
     */
    public function propResolved(string $path, mixed $prop): void
    {
        $this->recordProp($path, $prop);
    }

    /**
     * Record a deferred prop whose resolver threw but was rescued (`Inertia::defer(rescue: true)`).
     * It is skipped from the response props before normal metadata collection, so it needs its
     * own hook: capture the defer type/group and flag it rescued, with no resolved value.
     */
    public function propRescued(string $path, mixed $prop): void
    {
        $this->recordProp($path, $prop, rescued: true);
    }

    /**
     * Classify the prop and add it to the collector.
     */
    protected function recordProp(string $path, mixed $prop, bool $rescued = false): void
    {
        if ($this->collector === null) {
            return;
        }

        $meta = app(PropClassifier::class)->classifyResolved($path, $prop, app(Request::class));

        $this->collector->addProp(
            $path,
            $meta['inertiaType'],
            $meta['deferGroup'],
            $meta['reset'],
            $meta['once'],
            $meta['mergeDirection'],
            $meta['deepMerge'],
            rescued: $rescued,
        );
    }

    /**
     * Store the collected payload for the rendered page on the request.
     *
     * @param array<string, mixed> $page
     * @param array<array-key, mixed> $resolvedProps
     */
    public function pageRendered(Request $request, array $page, array $resolvedProps): void
    {
        if ($this->collector === null) {
            return;
        }

        $collector = $this->collector;

        if ($route = $request->route()) {
            $collector->setRoute(
                $route->getName(),
                '/' . ltrim($route->uri(), '/'),
                $request->method(),
            );

            $collector->setRouteAction($route->getActionName(), $route->getAction('uses'));
        }

        try {
            $collector->setComponentPath(App::make('inertia.view-finder')->find($page['component']));
        } catch (Throwable) {
        }

        $collector->setResolvedProps($resolvedProps);

        $payload = $collector->build();
        $payload['responseBody'] = $page;

        $request->attributes->set(RequestAttribute::PAYLOAD, $payload);
    }

    /**
     * Record the response sent for the request.
     */
    public function respondedWith(Request $request, SymfonyResponse $response): void
    {
        if (! DevTools::enabledForRequest($request)) {
            return;
        }

        try {
            $this->recordResponse($request, $response);
        } catch (Throwable) {
            // Recording is a passive observer: a malformed request, an unserializable
            // prop, or a misconfigured redact list must never turn the user's response
            // into a 500, so the failure is swallowed and the entry dropped.
        }
    }

    /**
     * Stamp the DevTools headers on the response and record its entry.
     */
    protected function recordResponse(Request $request, SymfonyResponse $response): void
    {
        // The middleware may replace a rendered page, as on a version change, so the page's
        // payload only describes an Inertia request's response while it is still the page.
        if ($request->header(Header::INERTIA) && ! $response->headers->has(Header::INERTIA)) {
            $request->attributes->remove(RequestAttribute::PAYLOAD);
        }

        $id = (string) Str::ulid();
        $isPrefetch = $request->prefetch();
        [$batchId, $parentOut] = $this->resolveLineage($request, $id);

        $basePath = $request->getBaseUrl();

        $response->headers->set(DevToolsHeader::DEVTOOLS_ID, $id);
        $response->headers->set(DevToolsHeader::DEVTOOLS_OUTGOING_PARENT, $parentOut);

        if ($basePath !== '') {
            $response->headers->set(DevToolsHeader::DEVTOOLS_BASE_PATH, $basePath);
        }

        if ($this->isInitialHtmlResponse($request, $response)) {
            $this->injectDevToolsIdTag($response, $id, $basePath);
        }

        $entry = app(IncomingEntryBuilder::class)->build($request, $response, $id, $batchId, $isPrefetch);

        app(EntryStore::class)->record($entry);
    }

    /**
     * Set the source location for the given shared prop keys.
     *
     * @param array<int, array-key> $keys
     * @param array{file: string, line: int} $source
     */
    protected function setShareSource(array $keys, array $source): void
    {
        $state = InertiaState::current();

        foreach ($keys as $key) {
            $state->shareSources[(string) $key] = $source;
        }
    }

    /**
     * Compute the top-level keys of the currently-shared props for DevTools
     * shared-vs-render annotations.
     *
     * @param array<array-key, mixed> $sharedProps
     * @return array<int, string>
     */
    protected function topLevelSharedKeys(array $sharedProps): array
    {
        return collect(array_keys($sharedProps))
            ->map(function (mixed $key): string {
                $key = (string) $key;

                return str_contains($key, '.') ? strstr($key, '.', true) : $key;
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether this response is the initial load of an Inertia page. A plain HTML page is
     * left alone: the extension reads the tag as devtools being enabled there, and would
     * warn about a missing interceptor registry that was never going to appear.
     */
    protected function isInitialHtmlResponse(Request $request, SymfonyResponse $response): bool
    {
        if ($request->header(Header::INERTIA)) {
            return false;
        }

        if (! $response->isOk()) {
            return false;
        }

        $payload = $request->attributes->get(RequestAttribute::PAYLOAD);

        if (! is_array($payload) || ($payload['component'] ?? null) === null) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        return str_contains(strtolower($contentType), 'text/html');
    }

    /**
     * Render the entry id into the page, along with the path the app is mounted on. A panel
     * that attaches after the initial load has only the DOM to read, and the entry endpoint
     * lives under that same path.
     */
    protected function injectDevToolsIdTag(SymfonyResponse $response, string $id, string $basePath): void
    {
        $content = $response->getContent();

        if (! is_string($content)) {
            return;
        }

        // HTML tag names are case-insensitive, so a root view may close its body as </BODY>.
        $closingBodyPosition = strripos($content, '</body>');

        if ($closingBodyPosition === false) {
            return;
        }

        $basePathAttribute = $basePath === ''
            ? ''
            : ' data-inertia-devtools-base-path="' . e($basePath) . '"';

        $tag = '<script data-inertia-devtools-id' . $basePathAttribute . ' type="application/json">' . json_encode($id) . '</script>';

        // Hypervel's setContent() replaces the response's original value with the string it is
        // given. For an Inertia page that value is the root view, which is where the testing
        // assertions read the page object from, so it is put back.
        $original = $response instanceof HttpResponse ? $response->original : null;

        $response->setContent(substr_replace($content, $tag, $closingBodyPosition, 0));

        if ($response instanceof HttpResponse) {
            $response->original = $original;
        }
    }

    /**
     * Resolve the batch root id sent back to the client.
     */
    protected function resolveOutgoingParentId(bool $isPrefetch, string $id, ?string $batchId): string
    {
        if ($isPrefetch) {
            return $id;
        }

        return $batchId ?? $id;
    }

    /**
     * Resolve the entry's batch id and the outgoing parent id.
     *
     * @return array{0: ?string, 1: string}
     */
    protected function resolveLineage(Request $request, string $id): array
    {
        $isPrefetch = $request->prefetch();
        $batchId = $request->header(Header::INERTIA)
            ? DevToolsHeader::read($request, DevToolsHeader::DEVTOOLS_INCOMING_PARENT)
            : null;

        return [$batchId, $this->resolveOutgoingParentId($isPrefetch, $id, $batchId)];
    }
}
