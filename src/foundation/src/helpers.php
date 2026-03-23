<?php

declare(strict_types=1);

use Carbon\Carbon;
use Hypervel\Broadcasting\FakePendingBroadcast;
use Hypervel\Broadcasting\PendingBroadcast;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastFactory;
use Hypervel\Contracts\Cookie\Factory as CookieFactory;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Contracts\Validation\Factory as ValidatorFactoryContract;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Cookie\CookieJar;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bus\PendingClosureDispatch;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\RedirectResponse;
use Hypervel\Log\LogManager;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Mix;
use Hypervel\Support\Stringable;
use Hypervel\Support\Uri;
use League\Uri\Contracts\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Support\enum_value;

if (! function_exists('abort')) {
    /**
     * Throw an HttpException with the given data.
     *
     * @param int|Responsable|Response $code
     *
     * @throws HttpException
     * @throws NotFoundHttpException
     * @throws HttpResponseException
     */
    function abort(mixed $code, string $message = '', array $headers = []): never
    {
        if ($code instanceof Response) {
            throw new HttpResponseException($code);
        }
        if ($code instanceof Responsable) {
            throw new HttpResponseException($code->toResponse(request()));
        }

        app()->abort($code, $message, $headers);
    }
}

if (! function_exists('abort_if')) {
    /**
     * Throw an HttpException with the given data if the given condition is true.
     *
     * @param int|Responsable|Response $code
     *
     * @throws HttpException
     * @throws NotFoundHttpException
     */
    function abort_if(bool $boolean, mixed $code, string $message = '', array $headers = []): void
    {
        if (! $boolean) {
            return;
        }

        abort($code, $message, $headers);
    }
}

if (! function_exists('abort_unless')) {
    /**
     * Throw an HttpException with the given data unless the given condition is true.
     *
     * @param int|Responsable|Response $code
     *
     * @throws HttpException
     * @throws NotFoundHttpException
     */
    function abort_unless(bool $boolean, mixed $code, string $message = '', array $headers = []): void
    {
        if ($boolean) {
            return;
        }

        abort($code, $message, $headers);
    }
}

if (! function_exists('action')) {
    /**
     * Generate the URL to a controller action.
     */
    function action(array|string $name, array|string $parameters = [], bool $absolute = true): string
    {
        return app('url')->action($name, $parameters, $absolute);
    }
}

if (! function_exists('app')) {
    /**
     * Get the available container instance.
     *
     * @template TClass of object
     *
     * @param null|class-string<TClass>|string $abstract
     *
     * @return ($abstract is class-string<TClass> ? TClass : ($abstract is null ? Application : mixed))
     */
    function app(?string $abstract = null, array $parameters = [])
    {
        if (is_null($abstract)) {
            return Container::getInstance();
        }

        return Container::getInstance()->make($abstract, $parameters);
    }
}

if (! function_exists('app_path')) {
    /**
     * Get the path to the application folder.
     */
    function app_path(string $path = ''): string
    {
        return join_paths(base_path('app'), $path);
    }
}

if (! function_exists('asset')) {
    /**
     * Generate an asset path for the application.
     */
    function asset(string $path, ?bool $secure = null): string
    {
        return app('url')->asset($path, $secure);
    }
}

if (! function_exists('auth')) {
    /**
     * Get the auth manager or a specific guard instance.
     *
     * @return ($guard is null ? AuthFactoryContract : Guard)
     */
    function auth(?string $guard = null): AuthFactoryContract|Guard
    {
        if (is_null($guard)) {
            return app(AuthFactoryContract::class);
        }

        return app(AuthFactoryContract::class)->guard($guard);
    }
}

if (! function_exists('back')) {
    /**
     * Create a new redirect response to the previous location.
     */
    function back(int $status = 302, array $headers = [], mixed $fallback = false): RedirectResponse
    {
        return app('redirect')->back($status, $headers, $fallback);
    }
}

if (! function_exists('base_path')) {
    /**
     * Get the path to the base of the install.
     */
    function base_path(string $path = ''): string
    {
        if (! Container::getInstance()->has(Application::class)) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->basePath($path);
    }
}

if (! function_exists('bcrypt')) {
    /**
     * Hash the given value against the bcrypt algorithm.
     */
    function bcrypt(string $value, array $options = []): string
    {
        /* @phpstan-ignore-next-line */
        return app('hash')->driver('bcrypt')->make($value, $options);
    }
}

if (! function_exists('broadcast')) {
    /**
     * Begin broadcasting an event.
     */
    function broadcast(mixed $event = null): PendingBroadcast
    {
        return app(BroadcastFactory::class)->event($event);
    }
}

if (! function_exists('broadcast_if')) {
    /**
     * Begin broadcasting an event if the given condition is true.
     */
    function broadcast_if(bool $boolean, mixed $event = null): PendingBroadcast
    {
        if ($boolean) {
            return app(BroadcastFactory::class)->event(value($event));
        }

        return new FakePendingBroadcast();
    }
}

if (! function_exists('broadcast_unless')) {
    /**
     * Begin broadcasting an event unless the given condition is true.
     */
    function broadcast_unless(bool $boolean, mixed $event = null): PendingBroadcast
    {
        return broadcast_if(! $boolean, $event);
    }
}

if (! function_exists('cache')) {
    /**
     * Get / set the specified cache value.
     *
     * If an array is passed, we'll assume you want to put to the cache.
     *
     * @param null|array<string, mixed>|string $key key|data
     * @param mixed $default default|expiration|null
     * @return ($key is null ? \Hypervel\Cache\CacheManager : ($key is string ? mixed : bool))
     *
     * @throws InvalidArgumentException
     */
    function cache($key = null, $default = null)
    {
        return \Hypervel\Cache\cache($key, $default);
    }
}

if (! function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
     *
     * @param null|array<string, mixed>|string $key
     * @return ($key is null ? \Hypervel\Config\Repository : ($key is string ? mixed : null))
     */
    function config(mixed $key = null, mixed $default = null): mixed
    {
        return \Hypervel\Config\config($key, $default);
    }
}

if (! function_exists('config_path')) {
    /**
     * Get the configuration path.
     */
    function config_path(string $path = ''): string
    {
        if (! Container::getInstance()->has(Application::class)) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, 'config', $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->configPath($path);
    }
}

if (! function_exists('cookie')) {
    /**
     * Create a new cookie instance.
     *
     * @return ($name is null ? \Hypervel\Cookie\CookieJar : Cookie)
     */
    function cookie(?string $name = null, ?string $value = null, int $minutes = 0, ?string $path = null, ?string $domain = null, ?bool $secure = null, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): CookieJar|Cookie
    {
        $cookieManager = app(CookieFactory::class);

        if (is_null($name)) {
            return $cookieManager;
        }

        return $cookieManager->make($name, $value, $minutes, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
    }
}

if (! function_exists('csrf_field')) {
    /**
     * Generate a CSRF token form field.
     */
    function csrf_field(): HtmlString
    {
        return \Hypervel\Session\csrf_field();
    }
}

if (! function_exists('csrf_token')) {
    /**
     * Get the CSRF token value.
     *
     * @throws \RuntimeException
     */
    function csrf_token(): ?string
    {
        return \Hypervel\Session\csrf_token();
    }
}

if (! function_exists('database_path')) {
    /**
     * Get the path to the database folder.
     */
    function database_path(string $path = ''): string
    {
        if (! Container::getInstance()->has(Application::class)) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, 'database', $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->databasePath($path);
    }
}

if (! function_exists('decrypt')) {
    /**
     * Decrypt the given value.
     */
    function decrypt(string $value, bool $unserialize = true): mixed
    {
        /* @phpstan-ignore-next-line */
        return app('encrypter')->decrypt($value, $unserialize);
    }
}

if (! function_exists('dispatch')) {
    /**
     * Dispatch a job to its appropriate handler.
     *
     * @param mixed $job
     * @return ($job is Closure ? PendingClosureDispatch : PendingDispatch)
     */
    function dispatch($job): PendingClosureDispatch|PendingDispatch
    {
        return \Hypervel\Bus\dispatch($job);
    }
}

if (! function_exists('dispatch_sync')) {
    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     */
    function dispatch_sync(mixed $job, mixed $handler = null): mixed
    {
        return \Hypervel\Bus\dispatch_sync($job, $handler);
    }
}

if (! function_exists('encrypt')) {
    /**
     * Encrypt the given value.
     */
    function encrypt(mixed $value, bool $serialize = true): string
    {
        /* @phpstan-ignore-next-line */
        return app('encrypter')->encrypt($value, $serialize);
    }
}

if (! function_exists('event')) {
    /**
     * Dispatch an event and call the listeners.
     */
    function event(mixed ...$args): mixed
    {
        return app('events')->dispatch(...$args);
    }
}

if (! function_exists('fake') && class_exists(\Faker\Factory::class)) {
    /**
     * Get a faker instance.
     */
    function fake(?string $locale = null): \Faker\Generator
    {
        if (app()->bound('config')) {
            $locale ??= app('config')->get('app.faker_locale');
        }

        $locale ??= 'en_US';

        $abstract = \Faker\Generator::class . ':' . $locale;

        if (! app()->bound($abstract)) {
            app()->singleton($abstract, fn () => \Faker\Factory::create($locale));
        }

        return app()->make($abstract);
    }
}

if (! function_exists('info')) {
    /**
     * @throws TypeError
     */
    function info(string|Stringable $message, array $context = [], bool $backtrace = false)
    {
        if ($backtrace) {
            $traces = debug_backtrace();
            $context['backtrace'] = sprintf('%s:%s', $traces[0]['file'], $traces[0]['line']);
        }

        return logger()->info($message, $context);
    }
}

if (! function_exists('lang_path')) {
    /**
     * Get the path to the language folder.
     */
    function lang_path(string $path = ''): string
    {
        if (! Container::getInstance()->has(Application::class)) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, 'lang', $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->langPath($path);
    }
}

if (! function_exists('logger')) {
    /**
     * Log a debug message to the logs.
     *
     * @return null|\Hypervel\Log\LogManager
     */
    function logger(?string $message = null, array $context = []): ?LoggerInterface
    {
        $logger = app(LoggerInterface::class);
        if (is_null($message)) {
            return $logger;
        }

        $logger->debug($message, $context);

        return null;
    }
}

if (! function_exists('logs')) {
    /**
     * Get a log driver instance.
     *
     * @return ($driver is null ? LogManager : LoggerInterface)
     */
    function logs(?string $driver = null): LoggerInterface|LogManager
    {
        return $driver ? app('log')->driver($driver) : app('log');
    }
}

if (! function_exists('method_field')) {
    /**
     * Generate a form field to spoof the HTTP verb used by forms.
     */
    function method_field(string $method): HtmlString
    {
        return new HtmlString('<input type="hidden" name="_method" value="' . $method . '">');
    }
}

if (! function_exists('mix')) {
    /**
     * Get the path to a versioned Mix file.
     *
     * @throws \RuntimeException
     */
    function mix(string $path, string $manifestDirectory = ''): HtmlString|string
    {
        return app(Mix::class)(...func_get_args());
    }
}

if (! function_exists('now')) {
    /**
     * Create a new Carbon instance for the current time.
     */
    function now(\UnitEnum|\DateTimeZone|string|null $tz = null): Carbon
    {
        return Carbon::now(enum_value($tz));
    }
}

if (! function_exists('old')) {
    /**
     * Retrieve an old input item.
     */
    function old(?string $key = null, mixed $default = null): string|array|null
    {
        return app('request')->old($key, $default);
    }
}

if (! function_exists('policy')) {
    /**
     * Get a policy instance for a given class.
     *
     * @return mixed|void
     * @throws InvalidArgumentException
     */
    function policy(object|string $class)
    {
        return app(Gate::class)->getPolicyFor($class);
    }
}

if (! function_exists('precognitive')) {
    /**
     * Handle a Precognition controller hook.
     */
    function precognitive(?callable $callable = null): mixed
    {
        $callable ??= function () {
        };

        $payload = $callable(function ($default, $precognition = null) {
            $response = request()->isPrecognitive()
                ? ($precognition ?? $default)
                : $default;

            abort(Router::toResponse(request(), value($response)));
        });

        if (request()->isPrecognitive()) {
            abort(204, headers: ['Precognition-Success' => 'true']);
        }

        return $payload;
    }
}

if (! function_exists('public_path')) {
    /**
     * Get the path to the public folder.
     */
    function public_path(string $path = ''): string
    {
        if (! Container::getInstance()->has(Application::class)) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, 'public', $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->publicPath($path);
    }
}

if (! function_exists('redirect')) {
    /**
     * Get an instance of the redirector or create a redirect response.
     */
    function redirect(?string $to = null, int $status = 302, array $headers = [], ?bool $secure = null): \Hypervel\Routing\Redirector|\Hypervel\Http\RedirectResponse
    {
        if (is_null($to)) {
            return app('redirect');
        }

        return app('redirect')->to($to, $status, $headers, $secure);
    }
}

if (! function_exists('report')) {
    /**
     * Report an exception.
     */
    function report(string|Throwable $exception): void
    {
        if (is_string($exception)) {
            $exception = new Exception($exception);
        }

        app(ExceptionHandlerContract::class)->report($exception);
    }
}

if (! function_exists('report_if')) {
    /**
     * Report an exception if the given condition is true.
     */
    function report_if(bool $boolean, string|Throwable $exception): void
    {
        if ($boolean) {
            report($exception);
        }
    }
}

if (! function_exists('report_unless')) {
    /**
     * Report an exception unless the given condition is true.
     */
    function report_unless(bool $boolean, string|Throwable $exception): void
    {
        if (! $boolean) {
            report($exception);
        }
    }
}

if (! function_exists('request')) {
    /**
     * Get an instance of the current request or an input item from the request.
     *
     * @param null|list<string>|string $key
     *
     * @return ($key is null ? \Hypervel\Http\Request : ($key is string ? mixed : array<string, mixed>))
     */
    function request(array|string|null $key = null, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return app('request');
        }

        if (is_array($key)) {
            return app('request')->only($key);
        }

        $value = app('request')->__get($key);

        return is_null($value) ? value($default) : $value;
    }
}

if (! function_exists('rescue')) {
    /**
     * Catch a potential exception and return a default value.
     *
     * @template TValue
     * @template TFallback
     *
     * @param callable(): TValue $callback
     * @param (callable(\Throwable): TFallback)|TFallback $rescue
     * @param bool|callable(\Throwable): bool $report
     * @return TFallback|TValue
     */
    function rescue(callable $callback, $rescue = null, $report = true)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if (value($report, $e)) {
                report($e);
            }

            return value($rescue, $e);
        }
    }
}

if (! function_exists('resolve')) {
    /**
     * Resolve a service from the container.
     *
     * @template T
     *
     * @param class-string<TClass>|string $name
     *
     * @return ($name is class-string<TClass> ? TClass : mixed)
     */
    function resolve(string $name, array $parameters = [])
    {
        return app($name, $parameters);
    }
}

if (! function_exists('resource_path')) {
    /**
     * Get the path to the resources folder.
     */
    function resource_path(string $path = ''): string
    {
        if (! Container::getInstance()->has(Application::class)) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, 'resources', $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->resourcePath($path);
    }
}

if (! function_exists('response')) {
    /**
     * Return a new response from the application.
     *
     * @return ($content is null ? \Hypervel\Contracts\Routing\ResponseFactory : \Hypervel\Http\Response)
     */
    function response(mixed $content = null, int $status = 200, array $headers = []): \Hypervel\Contracts\Routing\ResponseFactory|\Hypervel\Http\Response
    {
        $factory = app(\Hypervel\Contracts\Routing\ResponseFactory::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($content ?? '', $status, $headers);
    }
}

if (! function_exists('route')) {
    /**
     * Generate the URL to a named route.
     *
     * @param mixed $parameters
     *
     * @throws InvalidArgumentException
     */
    function route(BackedEnum|string $name, array $parameters = [], bool $absolute = true): string
    {
        return app('url')->route($name, $parameters, $absolute);
    }
}

if (! function_exists('secure_asset')) {
    /**
     * Generate an asset path for the application.
     */
    function secure_asset(string $path): string
    {
        return asset($path, true);
    }
}

if (! function_exists('secure_url')) {
    /**
     * Generate a HTTPS URL for the application.
     */
    function secure_url(string $path, array $extra = []): string
    {
        return url($path, $extra, true);
    }
}

if (! function_exists('session')) {
    /**
     * Get / set the specified session value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
     *
     * @return mixed|SessionContract
     */
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        return \Hypervel\Session\session($key, $default);
    }
}

if (! function_exists('storage_path')) {
    /**
     * Get the path to the storage folder.
     */
    function storage_path(string $path = ''): string
    {
        if (! Container::getInstance()->has(Application::class)) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, 'storage', $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->storagePath($path);
    }
}

if (! function_exists('to_action')) {
    /**
     * Create a new redirect response to a controller action.
     */
    function to_action(array|string $action, mixed $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return redirect()->action($action, $parameters, $status, $headers);
    }
}

if (! function_exists('to_route')) {
    /**
     * Create a new redirect response to a named route.
     */
    function to_route(string $route, array $parameters = [], int $status = 302, array $headers = []): \Hypervel\Http\RedirectResponse
    {
        return redirect()->route($route, $parameters, $status, $headers);
    }
}

if (! function_exists('today')) {
    /**
     * Create a new Carbon instance for the current date.
     */
    function today(\UnitEnum|\DateTimeZone|string|null $tz = null): Carbon
    {
        return Carbon::today(enum_value($tz));
    }
}

if (! function_exists('trans')) {
    /**
     * Translate the given message.
     *
     * @return ($key is null ? TranslatorContract : array|string)
     */
    function trans(?string $key = null, array $replace = [], ?string $locale = null): array|string|TranslatorContract
    {
        return \Hypervel\Translation\trans($key, $replace, $locale);
    }
}

if (! function_exists('trans_choice')) {
    /**
     * Translates the given message based on a count.
     */
    function trans_choice(string $key, array|Countable|float|int $number, array $replace = [], ?string $locale = null): string
    {
        return \Hypervel\Translation\trans_choice($key, $number, $replace, $locale);
    }
}

if (! function_exists('__')) {
    /**
     * Translate the given message.
     */
    function __(?string $key = null, array $replace = [], ?string $locale = null): array|string|TranslatorContract
    {
        return \Hypervel\Translation\trans($key, $replace, $locale);
    }
}

if (! function_exists('uri')) {
    /**
     * Generate a URI for the application.
     */
    function uri(UriInterface|Stringable|array|string $uri, mixed $parameters = [], bool $absolute = true): Uri
    {
        return match (true) {
            is_array($uri) || str_contains($uri, '\\') => Uri::action($uri, $parameters, $absolute),
            str_contains($uri, '.') && Route::has($uri) => Uri::route($uri, $parameters, $absolute),
            default => Uri::of($uri),
        };
    }
}

if (! function_exists('url')) {
    /**
     * Generate a URL for the application.
     *
     * @return ($path is null ? UrlGeneratorContract : string)
     */
    function url(?string $path = null, array $extra = [], ?bool $secure = null): string|UrlGeneratorContract
    {
        if (is_null($path)) {
            return app(UrlGeneratorContract::class);
        }

        return app(UrlGeneratorContract::class)->to($path, $extra, $secure);
    }
}

if (! function_exists('validator')) {
    /**
     * Create a new Validator instance.
     * @return ValidatorContract
     * @throws TypeError
     */
    function validator(array $data = [], array $rules = [], array $messages = [], array $customAttributes = [])
    {
        $factory = app(ValidatorFactoryContract::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($data, $rules, $messages, $customAttributes);
    }
}

if (! function_exists('view')) {
    /**
     * Get the evaluated view contents for the given view.
     */
    function view(?string $view = null, array|Arrayable $data = [], array $mergeData = []): ViewFactory|ViewContract
    {
        $factory = app(ViewFactory::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($view, $data, $mergeData);
    }
}

if (! function_exists('go')) {
    function go(callable $callable): bool|int
    {
        return \Hypervel\Coroutine\go($callable);
    }
}

if (! function_exists('co')) {
    function co(callable $callable): bool|int
    {
        return \Hypervel\Coroutine\co($callable);
    }
}

if (! function_exists('defer')) {
    /**
     * Defer execution of the given callback.
     *
     * When a name is provided, only the last callback registered with that
     * name will execute (named deduplication). Unnamed calls go directly
     * to Coroutine::defer() with zero overhead.
     */
    function defer(callable $callback, ?string $name = null): void
    {
        if ($name === null) {
            Coroutine::defer($callback);
            return;
        }

        // Register the drain hook BEFORE writing to Context (fail-fast:
        // if we're outside a coroutine, Co::defer() fails before any
        // Context mutation occurs).
        if (! Context::has('__foundation.deferred_callbacks_registered')) {
            Coroutine::defer(function () {
                $callbacks = Context::get('__foundation.deferred_callbacks', []);
                foreach ($callbacks as $deferred) {
                    $deferred();
                }
            });
            Context::set('__foundation.deferred_callbacks_registered', true);
        }

        Context::override('__foundation.deferred_callbacks', function (?array $callbacks) use ($name, $callback) {
            $callbacks ??= [];
            $callbacks[$name] = $callback;
            return $callbacks;
        });
    }
}
