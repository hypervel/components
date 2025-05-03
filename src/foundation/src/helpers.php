<?php

declare(strict_types=1);

use Carbon\Carbon;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\HttpMessage\Cookie\Cookie;
use Hyperf\Stringable\Stringable;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use Hyperf\ViewEngine\Contract\ViewInterface;
use Hypervel\Auth\Contracts\Factory as AuthFactoryContract;
use Hypervel\Auth\Contracts\Gate;
use Hypervel\Auth\Contracts\Guard;
use Hypervel\Broadcasting\Contracts\Factory as BroadcastFactory;
use Hypervel\Broadcasting\PendingBroadcast;
use Hypervel\Bus\PendingClosureDispatch;
use Hypervel\Bus\PendingDispatch;
use Hypervel\Container\Contracts\Container;
use Hypervel\Cookie\Contracts\Cookie as CookieContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\HttpMessage\Exceptions\HttpResponseException;
use Hypervel\HttpMessage\Exceptions\NotFoundHttpException;
use Hypervel\Router\Contracts\UrlGenerator as UrlGeneratorContract;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Support\Contracts\Responsable;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Mix;
use Hypervel\Translation\Contracts\Translator as TranslatorContract;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

use function Hypervel\Filesystem\join_paths;

if (! function_exists('abort')) {
    /**
     * Throw an HttpException with the given data.
     *
     * @param int|Responsable $code
     *
     * @throws HttpException
     * @throws NotFoundHttpException
     * @throws HttpResponseException
     */
    function abort(mixed $code, string $message = '', array $headers = []): void
    {
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
     * @param int|Responsable $code
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
     * @param int|Responsable $code
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

if (! function_exists('base_path')) {
    /**
     * Get the path to the base of the install.
     */
    function base_path(string $path = ''): string
    {
        if (! ApplicationContext::hasContainer()) {
            return defined('BASE_PATH')
                ? join_paths(BASE_PATH, $path)
                : throw new RuntimeException('BASE_PATH constant is not defined.');
        }

        return app()->basePath($path);
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

if (! function_exists('broadcast')) {
    /**
     * Begin broadcasting an event.
     */
    function broadcast(mixed $event = null): PendingBroadcast
    {
        return app(BroadcastFactory::class)->event($event);
    }
}

if (! function_exists('database_path')) {
    /**
     * Get the path to the database folder.
     */
    function database_path(string $path = ''): string
    {
        return app()->databasePath($path);
    }
}

if (! function_exists('storage_path')) {
    /**
     * Get the path to the storage folder.
     */
    function storage_path(string $path = ''): string
    {
        return app()->storagePath($path);
    }
}

if (! function_exists('config_path')) {
    /**
     * Get the configuration path.
     */
    function config_path(string $path = ''): string
    {
        return app()->configPath($path);
    }
}

if (! function_exists('resource_path')) {
    /**
     * Get the path to the resources folder.
     */
    function resource_path(string $path = ''): string
    {
        return app()->resourcePath($path);
    }
}

if (! function_exists('lang_path')) {
    /**
     * Get the path to the language folder.
     */
    function lang_path(string $path = ''): string
    {
        return app()->langPath($path);
    }
}

if (! function_exists('public_path')) {
    /**
     * Get the path to the public folder.
     */
    function public_path(string $path = ''): string
    {
        return app()->publicPath($path);
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

if (! function_exists('method_field')) {
    /**
     * Generate a form field to spoof the HTTP verb used by forms.
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . $method . '">';
    }
}

if (! function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
     *
     * @param null|array<string, mixed>|string $key
     * @return ($key is null ? \Hypervel\Config\Contracts\Repository : ($key is string ? mixed : null))
     */
    function config(mixed $key = null, mixed $default = null): mixed
    {
        return \Hypervel\Config\config($key, $default);
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

if (! function_exists('cookie')) {
    /**
     * Create a new cookie instance.
     *
     * @return Cookie|CookieContract
     */
    function cookie(string $name, string $value, int $minutes = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null)
    {
        $cookieManager = app(CookieContract::class);
        if (is_null($name)) {
            return $cookieManager;
        }

        return $cookieManager->make($name, $value, $minutes, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
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

if (! function_exists('csrf_field')) {
    /**
     * Generate a CSRF token form field.
     */
    function csrf_field(): HtmlString
    {
        return \Hypervel\Session\csrf_field();
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
        if (ApplicationContext::hasContainer()) {
            /** @var Container $container */
            $container = ApplicationContext::getContainer();

            if (is_null($abstract)) {
                return $container;
            }

            if (count($parameters) == 0 && $container->has($abstract)) {
                return $container->get($abstract);
            }

            return $container->make($abstract, $parameters);
        }

        if (is_null($abstract)) {
            throw new InvalidArgumentException('Invalid argument $abstract');
        }

        return new $abstract(...array_values($parameters));
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

if (! function_exists('event')) {
    /**
     * Dispatch an event and call the listeners.
     *
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     */
    function event(object $event)
    {
        return \Hypervel\Event\event($event);
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
    function now(null|\DateTimeZone|string $tz = null): Carbon
    {
        return Carbon::now($tz);
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

if (! function_exists('request')) {
    /**
     * Get an instance of the current request or an input item from the request.
     *
     * @return ($key is null ? RequestContract : ($key is string ? mixed : array<string, mixed>))
     *
     * @throws TypeError
     */
    function request(null|array|string $key = null, mixed $default = null): mixed
    {
        $request = app(RequestContract::class);

        if (is_null($key)) {
            return $request;
        }

        if (is_array($key)) {
            return $request->inputs($key, value($default));
        }

        return $request->input($key, value($default));
    }
}

if (! function_exists('response')) {
    /**
     * Return a new response from the application.
     *
     * @return ($content is null ? ResponseContract : ResponseInterface)
     */
    function response(mixed $content = null, int $status = 200, array $headers = []): ResponseContract|ResponseInterface
    {
        $response = app(ResponseContract::class);

        if (func_num_args() === 0) {
            return $response;
        }

        return $response->make($content ?? '', $status, $headers);
    }
}

if (! function_exists('redirect')) {
    /**
     * Return a new response from the application.
     */
    function redirect(string $toUrl, int $status = 302, string $schema = 'http'): ResponseInterface
    {
        return app(ResponseContract::class)
            ->redirect($toUrl, $status, $schema);
    }
}

if (! function_exists('to_route')) {
    /**
     * Create a new redirect response to a named route.
     */
    function to_route(string $route, array $parameters = [], int $status = 302, array $headers = []): ResponseInterface
    {
        $response = redirect(route($route, $parameters), $status);
        if ($headers) {
            foreach ($headers as $key => $value) {
                $response = $response->withHeader($key, $value);
            }
        }

        return $response;
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

if (! function_exists('session')) {
    /**
     * Get / set the specified session value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
     *
     * @return mixed|SessionContract
     */
    function session(null|array|string $key = null, mixed $default = null): mixed
    {
        return \Hypervel\Session\session($key, $default);
    }
}

if (! function_exists('today')) {
    /**
     * Create a new Carbon instance for the current date.
     *
     * @param null|\DateTimeZone|string $tz
     */
    function today($tz = null): Carbon
    {
        return Carbon::today($tz);
    }
}

if (! function_exists('validator')) {
    /**
     * Create a new Validator instance.
     * @return ValidatorFactoryInterface|ValidatorInterface
     * @throws TypeError
     */
    function validator(array $data = [], array $rules = [], array $messages = [], array $customAttributes = [])
    {
        $factory = app(ValidatorFactoryInterface::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($data, $rules, $messages, $customAttributes);
    }
}

if (! function_exists('route')) {
    /**
     * Get the URL to a named route.
     *
     * @throws InvalidArgumentException
     */
    function route(string $name, array $parameters = [], bool $absolute = true, string $server = 'http'): string
    {
        return \Hypervel\Router\route($name, $parameters, $absolute, $server);
    }
}

if (! function_exists('url')) {
    /**
     * Generate a url for the application.
     */
    function url(?string $path = null, array $extra = [], ?bool $secure = null): string|UrlGeneratorContract
    {
        return \Hypervel\Router\url($path, $extra, $secure);
    }
}

if (! function_exists('secure_url')) {
    /**
     * Generate a secure, absolute URL to the given path.
     */
    function secure_url(string $path, array $extra = []): string
    {
        return \Hypervel\Router\secure_url($path, $extra);
    }
}

if (! function_exists('asset')) {
    /**
     * Generate an asset path for the application.
     */
    function asset(string $path, ?bool $secure = null): string
    {
        return \Hypervel\Router\asset($path, $secure);
    }
}

if (! function_exists('auth')) {
    /**
     * Get auth guard.
     *
     * @return ($guard is null ? AuthFactoryContract&Guard : Guard)
     */
    function auth(?string $guard = null): mixed
    {
        return \Hypervel\Auth\auth($guard);
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
    function __(?string $key = null, array $replace = [], ?string $locale = null): null|array|string
    {
        return \Hypervel\Translation\trans($key, $replace, $locale);
    }
}

if (! function_exists('view')) {
    /**
     * Get the evaluated view contents for the given view.
     *
     * @param null|string $view
     * @param array $mergeData
     */
    function view($view = null, array|Arrayable $data = [], $mergeData = []): FactoryInterface|ViewInterface
    {
        $factory = app(FactoryInterface::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($view, $data, $mergeData);
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
    function defer(callable $callable): void
    {
        \Hypervel\Coroutine\defer($callable);
    }
}
