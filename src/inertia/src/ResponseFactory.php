<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use BackedEnum;
use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Inertia\Ssr\DisablesSsr;
use Hypervel\Inertia\Ssr\ExcludesSsrPaths;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Support\Header;
use Hypervel\Inertia\Support\SessionKey;
use Hypervel\Routing\Router;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\Redirect;
use Hypervel\Support\Facades\Request;
use Hypervel\Support\Facades\Response as BaseResponse;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use UnitEnum;

class ResponseFactory
{
    use Macroable;

    /**
     * Get the per-request Inertia state.
     */
    private function state(): InertiaState
    {
        return CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState);
    }

    /**
     * Set the root view template for Inertia responses. This template
     * serves as the HTML wrapper that contains the Inertia root element
     * where the frontend application will be mounted.
     */
    public function setRootView(string $name): void
    {
        $this->state()->rootView = $name;
    }

    /**
     * Share data across all Inertia responses. This data is automatically
     * included with every response, making it ideal for user authentication
     * state, flash messages, etc.
     *
     * @param array<array-key, mixed>|Arrayable<array-key, mixed>|ProvidesInertiaProperties|string $key
     */
    public function share(mixed $key, mixed $value = null): void
    {
        $state = $this->state();

        if (is_array($key)) {
            $state->sharedProps = array_merge($state->sharedProps, $key);
        } elseif ($key instanceof Arrayable) {
            $state->sharedProps = array_merge($state->sharedProps, $key->toArray());
        } elseif ($key instanceof ProvidesInertiaProperties) {
            $state->sharedProps = array_merge($state->sharedProps, [$key]);
        } else {
            Arr::set($state->sharedProps, $key, $value);
        }
    }

    /**
     * Get the shared data for a given key. Returns all shared data if
     * no key is provided, or the value for a specific key with an
     * optional default fallback.
     */
    public function getShared(?string $key = null, mixed $default = null): mixed
    {
        $sharedProps = $this->state()->sharedProps;

        if ($key) {
            return Arr::get($sharedProps, $key, $default);
        }

        return $sharedProps;
    }

    /**
     * Flush all shared data.
     */
    public function flushShared(): void
    {
        $this->state()->sharedProps = [];
    }

    /**
     * Set the asset version.
     */
    public function version(Closure|string|null $version): void
    {
        $this->state()->version = $version;
    }

    /**
     * Get the asset version.
     */
    public function getVersion(): string
    {
        $version = $this->state()->version;

        $version = $version instanceof Closure
            ? App::call($version)
            : $version;

        return (string) $version;
    }

    /**
     * Set the URL resolver.
     */
    public function resolveUrlUsing(?Closure $urlResolver = null): void
    {
        $this->state()->urlResolver = $urlResolver;
    }

    /**
     * Set the component transformer.
     */
    public function transformComponentUsing(?Closure $componentTransformer = null): void
    {
        $this->state()->componentTransformer = $componentTransformer;
    }

    /**
     * Clear the browser history on the next visit.
     */
    public function clearHistory(): void
    {
        session([SessionKey::CLEAR_HISTORY => true]);
    }

    /**
     * Preserve the URL fragment across the next redirect.
     */
    public function preserveFragment(): void
    {
        session([SessionKey::PRESERVE_FRAGMENT => true]);
    }

    /**
     * Encrypt the browser history.
     */
    public function encryptHistory(bool $encrypt = true): void
    {
        $this->state()->encryptHistory = $encrypt;
    }

    /**
     * Disable server-side rendering, optionally based on a condition.
     */
    public function disableSsr(Closure|bool $condition = true): void
    {
        $gateway = app(Gateway::class);

        if (! $gateway instanceof DisablesSsr) {
            throw new LogicException('The configured SSR gateway does not support disabling server-side rendering conditionally.');
        }

        $gateway->disable($condition);
    }

    /**
     * Exclude the given paths from server-side rendering.
     *
     * @param array<int, string>|string $paths
     */
    public function withoutSsr(array|string $paths): void
    {
        $gateway = app(Gateway::class);

        if (! $gateway instanceof ExcludesSsrPaths) {
            throw new LogicException('The configured SSR gateway does not support excluding paths from server-side rendering.');
        }

        $gateway->except($paths);
    }

    /**
     * Create an optional property.
     */
    public function optional(callable $callback): OptionalProp
    {
        return new OptionalProp($callback);
    }

    /**
     * Create a deferred property.
     */
    public function defer(callable $callback, string $group = 'default'): DeferProp
    {
        return new DeferProp($callback, $group);
    }

    /**
     * Create a merge property.
     */
    public function merge(mixed $value): MergeProp
    {
        return new MergeProp($value);
    }

    /**
     * Create a deep merge property.
     */
    public function deepMerge(mixed $value): MergeProp
    {
        return (new MergeProp($value))->deepMerge();
    }

    /**
     * Create an always property.
     */
    public function always(mixed $value): AlwaysProp
    {
        return new AlwaysProp($value);
    }

    /**
     * Create a scroll property.
     *
     * @template T
     *
     * @param T $value
     * @return ScrollProp<T>
     */
    public function scroll(mixed $value, string $wrapper = 'data', ProvidesScrollMetadata|callable|null $metadata = null): ScrollProp
    {
        return new ScrollProp($value, $wrapper, $metadata);
    }

    /**
     * Create a once property.
     */
    public function once(callable $value): OnceProp
    {
        return new OnceProp($value);
    }

    /**
     * Create and share a once property.
     */
    public function shareOnce(string $key, callable $callback): OnceProp
    {
        return tap(new OnceProp($callback), fn ($prop) => $this->share($key, $prop));
    }

    /**
     * Find the component or fail.
     *
     * @throws ComponentNotFoundException
     */
    protected function findComponentOrFail(string $component): void
    {
        try {
            app('inertia.view-finder')->find($component);
        } catch (InvalidArgumentException) {
            throw new ComponentNotFoundException("Inertia page component [{$component}] not found.");
        }
    }

    /**
     * Transform the component name.
     */
    protected function transformComponent(mixed $component): mixed
    {
        $transformer = $this->state()->componentTransformer;

        if (! $transformer) {
            return $component;
        }

        return $transformer($component) ?? $component;
    }

    /**
     * Create an Inertia response.
     *
     * @param BackedEnum|string|UnitEnum $component
     * @param array<array-key, mixed>|Arrayable<array-key, mixed>|ProvidesInertiaProperties $props
     */
    public function render(mixed $component, mixed $props = []): Response
    {
        $component = $this->transformComponent($component);

        $component = match (true) {
            $component instanceof BackedEnum => $component->value,
            $component instanceof UnitEnum => $component->name,
            default => $component,
        };

        if (! is_string($component)) {
            throw new InvalidArgumentException('Component argument must be of type string or a string BackedEnum');
        }

        if (config('inertia.pages.ensure_pages_exist', false)) {
            $this->findComponentOrFail($component);
        }

        if ($props instanceof Arrayable) {
            $props = $props->toArray();
        } elseif ($props instanceof ProvidesInertiaProperties) {
            // Will be resolved in PropsResolver::resolvePropertyProviders()
            $props = [$props];
        }

        $state = $this->state();

        return new Response(
            $component,
            $state->sharedProps,
            $props,
            $state->rootView,
            $this->getVersion(),
            $state->encryptHistory ?? (bool) config('inertia.history.encrypt', false),
            $state->urlResolver,
        );
    }

    /**
     * Create an Inertia location response.
     */
    public function location(string|RedirectResponse $url): SymfonyResponse
    {
        if (Request::inertia()) {
            return BaseResponse::make('', 409, [Header::LOCATION => $url instanceof RedirectResponse ? $url->getTargetUrl() : $url]);
        }

        return $url instanceof RedirectResponse ? $url : Redirect::away($url);
    }

    /**
     * Register a callback to handle HTTP exceptions for Inertia requests.
     *
     * This is boot-time configuration — call once in a service provider's
     * boot() method. The callback is stored on the exception handler singleton
     * and applies to all requests for the worker lifetime.
     */
    public function handleExceptionsUsing(callable $callback): void
    {
        /** @var mixed $handler */
        $handler = app(ExceptionHandlerContract::class);

        if (! $handler instanceof ExceptionHandler) {
            if (app()->runningInConsole()) {
                return;
            }

            if (! method_exists($handler, 'respondUsing')) {
                throw new LogicException('The bound exception handler does not have a `respondUsing` method.');
            }
        }

        /** @var ExceptionHandler $handler */
        $handler->respondUsing(function ($response, $e, $request) use ($callback) {
            $result = $callback(new ExceptionResponse(
                $e,
                $request,
                $response,
                app(Router::class),
                app(Kernel::class),
            ));

            if ($result instanceof ExceptionResponse) {
                return $result->toResponse($request);
            }

            return $result ?? $response;
        });
    }

    /**
     * Flash data to be included with the next response. Unlike regular props,
     * flash data is not persisted in the browser's history state, making it
     * ideal for one-time notifications like toasts or highlights.
     *
     * @param array<string, mixed>|BackedEnum|string|UnitEnum $key
     */
    public function flash(BackedEnum|UnitEnum|string|array $key, mixed $value = null): self
    {
        $flash = $key;

        if (! is_array($key)) {
            $key = match (true) {
                $key instanceof BackedEnum => $key->value,
                $key instanceof UnitEnum => $key->name,
                default => $key,
            };

            $flash = [$key => $value];
        }

        session()->flash(SessionKey::FLASH_DATA, [
            ...$this->getFlashed(),
            ...$flash,
        ]);

        return $this;
    }

    /**
     * Create a new redirect response to the previous location.
     *
     * @param array<string, string> $headers
     */
    public function back(int $status = 302, array $headers = [], mixed $fallback = false): RedirectResponse
    {
        return Redirect::back($status, $headers, $fallback);
    }

    /**
     * Retrieve the flashed data from the session.
     *
     * @return array<string, mixed>
     */
    public function getFlashed(?HttpRequest $request = null): array
    {
        $request ??= request();

        return $request->hasSession() ? $request->session()->get(SessionKey::FLASH_DATA, []) : [];
    }

    /**
     * Retrieve and remove the flashed data from the session.
     *
     * @return array<string, mixed>
     */
    public function pullFlashed(?HttpRequest $request = null): array
    {
        $request ??= request();

        return $request->hasSession() ? $request->session()->pull(SessionKey::FLASH_DATA, []) : [];
    }

    /**
     * Reset the per-request Inertia state.
     */
    public static function flushState(): void
    {
        CoroutineContext::forget(InertiaState::CONTEXT_KEY);
    }
}
