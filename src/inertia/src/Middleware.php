<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Closure;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Inertia\DevTools\DevTools;
use Hypervel\Inertia\Ssr\ExcludesSsrPaths;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Support\Header;
use Hypervel\Inertia\Support\RequestAttribute;
use Hypervel\Inertia\Support\SessionKey;
use Hypervel\Routing\Route;
use Hypervel\Session\Store;
use Hypervel\Support\Facades\Redirect;
use Hypervel\Support\MessageBag;
use Symfony\Component\HttpFoundation\Response;

class Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     */
    protected string $rootView = 'app';

    /**
     * Determines if validation errors should be mapped to a single error message per field.
     */
    protected bool $withAllErrors = false;

    /**
     * The paths that should be excluded from server-side rendering.
     *
     * @var array<int, string>
     */
    protected array $withoutSsr = [];

    /**
     * The cached asset version for this worker.
     */
    private static ?string $cachedVersion = null;

    /**
     * Whether the version has been computed for this worker.
     */
    private static bool $versionComputed = false;

    /**
     * Determine the current asset version.
     *
     * The result is cached for the worker lifetime to avoid
     * repeated filesystem I/O on every request.
     */
    public function version(Request $request): ?string
    {
        if (! self::$versionComputed) {
            self::$cachedVersion = $this->computeVersion();
            self::$versionComputed = true;
        }

        return self::$cachedVersion;
    }

    /**
     * Compute the asset version from the manifest file.
     */
    private function computeVersion(): ?string
    {
        $assetUrl = config()->nullableString('app.asset_url');

        if ($assetUrl) {
            return hash('xxh128', $assetUrl);
        }

        if (file_exists($manifest = public_path('build/manifest.json'))) {
            return hash_file('xxh128', $manifest) ?: null;
        }

        if (file_exists($manifest = public_path('mix-manifest.json'))) {
            return hash_file('xxh128', $manifest) ?: null;
        }

        return null;
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            'errors' => Inertia::always($this->resolveValidationErrors($request)),
        ];
    }

    /**
     * Define the props that are shared once and remembered across navigations.
     *
     * @return array<string, callable|OnceProp>
     */
    public function shareOnce(Request $request): array
    {
        return [];
    }

    /**
     * Set the root template that is loaded on the first page visit.
     */
    public function rootView(Request $request): string
    {
        return $this->rootView;
    }

    /**
     * Define a callback that returns the relative URL.
     */
    public function urlResolver(): ?Closure
    {
        return null;
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $recorder = DevTools::recorder($request);

        $recorder?->requestStarted($request);

        Inertia::version(function () use ($request) {
            return $this->version($request);
        });

        $shared = $this->share($request);

        Inertia::share($shared);

        $recorder?->sharedPropsResolved($this, $shared);

        foreach ($this->shareOnce($request) as $key => $value) {
            if ($value instanceof OnceProp) {
                Inertia::share($key, $value);
            } else {
                Inertia::shareOnce($key, $value);
            }
        }

        Inertia::setRootView($this->rootView($request));

        if (($urlResolver = $this->urlResolver()) !== null) {
            Inertia::resolveUrlUsing($urlResolver);
        }

        $ssrGateway = app(Gateway::class);

        if (! empty($this->withoutSsr) && $ssrGateway instanceof ExcludesSsrPaths) {
            $ssrGateway->except($this->withoutSsr);
        }

        $response = $next($request);

        // The client follows a location response with a full page visit, so we will
        // keep the flash data for that visit, just like we do for redirects.
        if ($response->isRedirect() || $this->isLocationResponse($response)) {
            $this->reflash($request);
        }

        if (! $request->header(Header::INERTIA)) {
            $this->addInertiaVaryHeader($response);

            $recorder?->respondedWith($request, $response);

            return $response;
        }

        $originalResponse = $response;

        if ($request->method() === 'GET' && $request->header(Header::VERSION, '') !== Inertia::getVersion()) {
            $response = $this->onVersionChange($request, $response);
        }

        if ($response->isOk() && $response->getContent() === '') {
            $response = $this->onEmptyResponse($request, $response);

            // Re-check the final response, since onEmptyResponse() may return a redirect or location response.
            if ($response->isRedirect() || $this->isLocationResponse($response)) {
                $this->reflash($request);
            }
        }

        // Back redirects must read the previous location before this visit replaces it.
        $this->storeCurrentUrl($request, $originalResponse);

        if ($response->getStatusCode() === 302 && in_array($request->method(), ['PUT', 'PATCH', 'DELETE', 'QUERY'], true)) {
            $response->setStatusCode(303);
        }

        // Check the final response so redirects returned by onEmptyResponse() also receive fragment handling.
        if ($response->isRedirect() && $this->redirectHasFragment($response) && ! $request->prefetch()) {
            $response = $this->onRedirectWithFragment($request, $response);
        }

        $this->addInertiaVaryHeader($response);

        $recorder?->respondedWith($request, $response);

        return $response;
    }

    /**
     * Store the current URL and route as the previous location, which Hypervel's
     * session middleware skips for Inertia visits.
     */
    protected function storeCurrentUrl(Request $request, Response $response): void
    {
        if (! $this->shouldStoreCurrentUrl($request, $response)) {
            return;
        }

        /** @var Store $session */
        $session = $request->session();
        $session->setPreviousUrl($request->fullUrl());
        $session->setPreviousRoute($request->route()?->getName());
    }

    /**
     * Determine if the visit should be stored as the previous location. Partial
     * reloads are excluded, since deferred props, polling, and infinite scroll
     * requests aren't navigations the user came from.
     */
    public function shouldStoreCurrentUrl(Request $request, Response $response): bool
    {
        if (! config()->boolean('inertia.store_previous_url')
            || ! $request->hasSession()
            || ! $request->isMethod('GET')
            || ! $request->route() instanceof Route
            || ! $request->ajax()
            || $request->prefetch()
            || $request->isPrecognitive()) {
            return false;
        }

        return ! $this->isPartialReload($request, $response);
    }

    /**
     * Determine if the request is a partial reload of the component that was rendered.
     */
    protected function isPartialReload(Request $request, Response $response): bool
    {
        if (! $component = $request->header(Header::PARTIAL_COMPONENT)) {
            return false;
        }

        return $response instanceof JsonResponse
            && $component === data_get($response->getOriginalContent(), 'component');
    }

    /**
     * Add the Inertia header to the response's Vary list.
     */
    protected function addInertiaVaryHeader(Response $response): void
    {
        // Keep case-insensitive matching without allocating a Collection on every response.
        $vary = array_unique(array_filter($response->getVary()));
        $present = array_any($vary, static fn (string $header): bool => strcasecmp($header, Header::INERTIA) === 0);

        $response->setVary(implode(', ', $present ? $vary : [...$vary, Header::INERTIA]));
    }

    /**
     * Determine if the redirect response contains a URL fragment.
     */
    protected function redirectHasFragment(Response $response): bool
    {
        return str_contains($response->headers->get('Location', ''), '#');
    }

    /**
     * Determine if the response instructs the client to visit another location.
     */
    protected function isLocationResponse(Response $response): bool
    {
        return $response->getStatusCode() === 409
            && $response->headers->has(Header::LOCATION);
    }

    /**
     * Reflash the session data for the next request.
     */
    protected function reflash(Request $request): void
    {
        if ($flashed = Inertia::getFlashed($request)) {
            $request->session()->flash(SessionKey::FLASH_DATA, $flashed);
        }
    }

    /**
     * Handle empty responses.
     */
    public function onEmptyResponse(Request $request, Response $response): Response
    {
        return Redirect::back();
    }

    /**
     * Handle redirects with URL fragments.
     */
    public function onRedirectWithFragment(Request $request, Response $response): Response
    {
        return response('', 409, [
            Header::REDIRECT => $response->headers->get('Location'),
        ]);
    }

    /**
     * Handle version changes.
     */
    public function onVersionChange(Request $request, Response $response): Response
    {
        if ($request->hasSession()) {
            /** @var Store $session */
            $session = $request->session();
            $session->reflash();

            // The rendered page is discarded in favor of a full page reload, so we will
            // put back the flash data it pulled from the session for the next request.
            if ($pulled = $request->attributes->get(RequestAttribute::PULLED_FLASH_DATA)) {
                $session->flash(SessionKey::FLASH_DATA, [...$pulled, ...Inertia::getFlashed($request)]);
            }
        }

        $response = Inertia::location($request->fullUrl());
        $response->headers->set(Header::VERSION, Inertia::getVersion());

        return $response;
    }

    /**
     * Resolve validation errors for client-side use.
     */
    public function resolveValidationErrors(Request $request): object
    {
        if (! $request->hasSession() || ! $request->session()->has('errors')) {
            return (object) [];
        }

        /** @var array<string, MessageBag> $bags */
        $bags = $request->session()->get('errors')->getBags();

        return (object) collect($bags)->map(function ($bag) {
            return (object) collect($bag->messages())->map(function ($errors) {
                return $this->withAllErrors ? $errors : $errors[0];
            })->all();
        })->pipe(function ($bags) use ($request) {
            $errorBag = $request->header(Header::ERROR_BAG);

            if ($bags->has('default') && $errorBag !== null && $errorBag !== '') {
                return [$errorBag => $bags->get('default')];
            }

            if ($bags->has('default')) {
                return $bags->get('default');
            }

            return $bags->toArray();
        });
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$cachedVersion = null;
        self::$versionComputed = false;
    }
}
