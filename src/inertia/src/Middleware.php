<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Inertia\Ssr\ExcludesSsrPaths;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Support\Header;
use Hypervel\Inertia\Support\SessionKey;
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
        if (config('app.asset_url')) {
            return hash('xxh128', (string) config('app.asset_url'));
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
        Inertia::version(function () use ($request) {
            return $this->version($request);
        });

        Inertia::share($this->share($request));

        foreach ($this->shareOnce($request) as $key => $value) {
            if ($value instanceof OnceProp) {
                Inertia::share($key, $value);
            } else {
                Inertia::shareOnce($key, $value);
            }
        }

        Inertia::setRootView($this->rootView($request));

        if ($urlResolver = $this->urlResolver()) {
            Inertia::resolveUrlUsing($urlResolver);
        }

        $ssrGateway = app(Gateway::class);

        if (! empty($this->withoutSsr) && $ssrGateway instanceof ExcludesSsrPaths) {
            $ssrGateway->except($this->withoutSsr);
        }

        $response = $next($request);
        $response->headers->set('Vary', Header::INERTIA);

        if ($isRedirect = $response->isRedirect()) {
            $this->reflash($request);
        }

        if (! $request->header(Header::INERTIA)) {
            return $response;
        }

        if ($request->method() === 'GET' && $request->header(Header::VERSION, '') !== Inertia::getVersion()) {
            $response = $this->onVersionChange($request, $response);
        }

        if ($response->isOk() && empty($response->getContent())) {
            $response = $this->onEmptyResponse($request, $response);
        }

        if ($response->getStatusCode() === 302 && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'])) {
            $response->setStatusCode(303);
        }

        if ($isRedirect && $this->redirectHasFragment($response) && ! $request->prefetch()) {
            $response = $this->onRedirectWithFragment($request, $response);
        }

        return $response;
    }

    /**
     * Determine if the redirect response contains a URL fragment.
     */
    protected function redirectHasFragment(Response $response): bool
    {
        return str_contains($response->headers->get('Location', ''), '#');
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
        }

        return Inertia::location($request->fullUrl());
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
            })->toArray();
        })->pipe(function ($bags) use ($request) {
            if ($bags->has('default') && $request->header(Header::ERROR_BAG)) {
                return [$request->header(Header::ERROR_BAG) => $bags->get('default')];
            }

            if ($bags->has('default')) {
                return $bags->get('default');
            }

            return $bags->toArray();
        });
    }

    /**
     * Reset the cached version state.
     */
    public static function flushState(): void
    {
        self::$cachedVersion = null;
        self::$versionComputed = false;
    }
}
