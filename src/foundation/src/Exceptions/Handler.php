<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions;

use Closure;
use Exception;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Cache\RateLimiting\Unlimited;
use Hypervel\Console\View\Components\BulletList;
use Hypervel\Console\View\Components\Error;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Debug\ShouldntReport;
use Hypervel\Contracts\Foundation\ExceptionRenderer;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\MultipleRecordsFoundException;
use Hypervel\Database\RecordNotFoundException;
use Hypervel\Database\RecordsNotFoundException;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\Exceptions\OriginMismatchException;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Hypervel\Routing\Router;
use Hypervel\Session\Store;
use Hypervel\Session\TokenMismatchException;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Lottery;
use Hypervel\Support\Reflector;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\ReflectsClosures;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Validation\ValidationException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use WeakMap;

class Handler implements ExceptionHandlerContract
{
    use ReflectsClosures;

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected array $dontReport = [];

    /**
     * The callbacks that should be used during reporting.
     *
     * @var ReportableHandler[]
     */
    protected array $reportCallbacks = [];

    /**
     * The callbacks that should be used to build exception context data.
     */
    protected array $contextCallbacks = [];

    /**
     * The callbacks that should be used to determine if an exception should not be reported.
     *
     * @var Closure[]
     */
    protected array $dontReportCallbacks = [];

    /**
     * The callbacks that should be used to throttle reportable exceptions.
     *
     * @var Closure[]
     */
    protected array $throttleCallbacks = [];

    /**
     * Indicate that throttle keys should be hashed.
     */
    protected bool $hashThrottleKeys = true;

    /**
     * The callbacks that should be used during rendering.
     *
     * @var Closure[]
     */
    protected array $renderCallbacks = [];

    /**
     * The callback that determines if the exception handler response should be JSON.
     *
     * @var null|callable
     */
    protected $shouldRenderJsonWhenCallback;

    /**
     * The callback that prepares responses to be returned to the browser.
     *
     * @var null|callable
     */
    protected $finalizeResponseCallback;

    /**
     * The registered exception mappings.
     *
     * @var array<string, Closure>
     */
    protected array $exceptionMap = [];

    /**
     * A map of exceptions with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, \Psr\Log\LogLevel::*>
     */
    protected array $levels = [];

    /**
     * A list of the internal exception types that should not be reported.
     *
     * @var array<int, class-string<RequestExceptionInterface>|class-string<Throwable>>
     */
    protected array $internalDontReport = [
        AuthenticationException::class,
        AuthorizationException::class,
        BackedEnumCaseNotFoundException::class,
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        MultipleRecordsFoundException::class,
        OriginMismatchException::class,
        RecordNotFoundException::class,
        RecordsNotFoundException::class,
        RequestExceptionInterface::class,
        TokenMismatchException::class,
        ValidationException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected array $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Indicate that an exception instance should only be reported once.
     */
    protected bool $withoutDuplicates = false;

    /**
     * Create a new exception handler instance.
     */
    public function __construct(
        protected Container $container
    ) {
        $this->register();
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
    }

    /**
     * Register a reportable callback.
     */
    public function reportable(callable $reportUsing): ReportableHandler
    {
        if (! $reportUsing instanceof Closure) {
            $reportUsing = Closure::fromCallable($reportUsing);
        }

        return tap(new ReportableHandler($reportUsing), function ($callback) {
            $this->reportCallbacks[] = $callback;
        });
    }

    /**
     * Register a renderable callback.
     */
    public function renderable(callable $renderUsing): static
    {
        if (! $renderUsing instanceof Closure) {
            $renderUsing = Closure::fromCallable($renderUsing);
        }

        $this->renderCallbacks[] = $renderUsing;

        return $this;
    }

    /**
     * Register a new exception mapping.
     *
     * @throws InvalidArgumentException
     */
    public function map(callable|string $from, Closure|string|null $to = null): static
    {
        if (is_string($to)) {
            $to = fn ($exception) => new $to('', 0, $exception);
        }

        if (is_callable($from) && is_null($to)) {
            $from = $this->firstClosureParameterType($to = $from);
        }

        if (! is_string($from) || ! $to instanceof Closure) {
            throw new InvalidArgumentException('Invalid exception mapping.');
        }

        $this->exceptionMap[$from] = $to;

        return $this;
    }

    /**
     * Indicate that the given exception type should not be reported.
     *
     * Alias of "ignore".
     */
    public function dontReport(array|string $exceptions): static
    {
        return $this->ignore($exceptions);
    }

    /**
     * Register a callback to determine if an exception should not be reported.
     *
     * @param (callable(Throwable): bool) $dontReportWhen
     */
    public function dontReportWhen(callable $dontReportWhen): static
    {
        if (! $dontReportWhen instanceof Closure) {
            $dontReportWhen = Closure::fromCallable($dontReportWhen);
        }

        $this->dontReportCallbacks[] = $dontReportWhen;

        return $this;
    }

    /**
     * Indicate that the given exception type should not be reported.
     */
    public function ignore(array|string $exceptions): static
    {
        $exceptions = Arr::wrap($exceptions);

        $this->dontReport = array_values(array_unique(array_merge($this->dontReport, $exceptions)));

        return $this;
    }

    /**
     * Indicate that the given attributes should never be flashed to the session on validation errors.
     */
    public function dontFlash(array|string $attributes): static
    {
        $this->dontFlash = array_values(array_unique(
            array_merge($this->dontFlash, Arr::wrap($attributes))
        ));

        return $this;
    }

    /**
     * Set the log level for the given exception type.
     *
     * @param class-string<Throwable> $type
     * @param \Psr\Log\LogLevel::* $level
     */
    public function level(string $type, string $level): static
    {
        $this->levels[$type] = $level;

        return $this;
    }

    /**
     * Report or log an exception.
     *
     * @throws Throwable
     */
    public function report(Throwable $e): void
    {
        $e = $this->mapException($e);

        if ($this->shouldntReport($e)) {
            return;
        }

        $this->reportThrowable($e);
    }

    /**
     * Report error based on report method on exception or to logger.
     *
     * @throws Throwable
     */
    protected function reportThrowable(Throwable $e): void
    {
        if ($this->withoutDuplicates) {
            $this->reportedException($e);
        }

        if (Reflector::isCallable($reportCallable = [$e, 'report'])
            && $this->container->call($reportCallable) !== false
        ) {
            return;
        }

        foreach ($this->reportCallbacks as $reportCallback) {
            if ($reportCallback->handles($e) && $reportCallback($e) === false) {
                return;
            }
        }

        try {
            $logger = $this->newLogger();
        } catch (Exception) {
            throw $e;
        }

        $level = $this->mapLogLevel($e);

        $context = $this->buildExceptionContext($e);

        method_exists($logger, $level)
            ? $logger->{$level}($e->getMessage(), $context)
            : $logger->log($level, $e->getMessage(), $context);
    }

    /**
     * Mark the given exception as reported.
     */
    protected function reportedException(Throwable $e): void
    {
        $this->reportedExceptionMap()[$e] = true;
    }

    /**
     * Determine if the given exception has already been reported.
     */
    protected function hasReportedException(Throwable $e): bool
    {
        return $this->reportedExceptionMap()->offsetExists($e);
    }

    /**
     * Get the reported exception map for the current request.
     */
    protected function reportedExceptionMap(): WeakMap
    {
        $map = Context::get('__errors.reportedExceptionMap');

        if (! $map instanceof WeakMap) {
            $map = new WeakMap();
            Context::set('__errors.reportedExceptionMap', $map);
        }

        return $map;
    }

    /**
     * Determine if the exception should be reported.
     */
    public function shouldReport(Throwable $e): bool
    {
        return ! $this->shouldntReport($e);
    }

    /**
     * Determine if the exception is in the "do not report" list.
     */
    protected function shouldntReport(Throwable $e): bool
    {
        if ($this->withoutDuplicates && $this->hasReportedException($e)) {
            return true;
        }

        if ($e instanceof ShouldntReport) {
            return true;
        }

        $dontReport = array_merge($this->dontReport, $this->internalDontReport);

        if (! is_null(Arr::first($dontReport, fn ($type) => $e instanceof $type))) {
            return true;
        }

        foreach ($this->dontReportCallbacks as $dontReportCallback) {
            if ($dontReportCallback($e) === true) {
                return true;
            }
        }

        return rescue(fn () => with($this->throttle($e), function ($throttle) use ($e) {
            if ($throttle instanceof Unlimited || $throttle === null) {
                return false;
            }

            if ($throttle instanceof Lottery) {
                return ! $throttle($e);
            }

            return ! $this->container->make(RateLimiter::class)->attempt(
                with($throttle->key ?: 'hypervel:foundation:exceptions:' . $e::class, fn ($key) => $this->hashThrottleKeys ? hash('xxh128', $key) : $key),
                $throttle->maxAttempts,
                fn () => true,
                $throttle->decaySeconds
            );
        }), rescue: false, report: false);
    }

    /**
     * Throttle the given exception.
     */
    protected function throttle(Throwable $e): Lottery|Limit|null
    {
        foreach ($this->throttleCallbacks as $throttleCallback) {
            foreach ($this->firstClosureParameterTypes($throttleCallback) as $type) {
                if (is_a($e, $type)) {
                    $response = $throttleCallback($e);

                    if (! is_null($response)) {
                        return $response;
                    }
                }
            }
        }

        return Limit::none();
    }

    /**
     * Specify the callback that should be used to throttle reportable exceptions.
     */
    public function throttleUsing(callable $throttleUsing): static
    {
        if (! $throttleUsing instanceof Closure) {
            $throttleUsing = Closure::fromCallable($throttleUsing);
        }

        $this->throttleCallbacks[] = $throttleUsing;

        return $this;
    }

    /**
     * Remove the given exception class from the list of exceptions that should be ignored.
     */
    public function stopIgnoring(array|string $exceptions): static
    {
        $exceptions = Arr::wrap($exceptions);

        $this->dontReport = (new Collection($this->dontReport))
            ->reject(fn ($ignored) => in_array($ignored, $exceptions))
            ->values()
            ->all();

        $this->internalDontReport = (new Collection($this->internalDontReport))
            ->reject(fn ($ignored) => in_array($ignored, $exceptions))
            ->values()
            ->all();

        return $this;
    }

    /**
     * Create the context array for logging the given exception.
     */
    protected function buildExceptionContext(Throwable $e): array
    {
        return array_merge(
            $this->exceptionContext($e),
            $this->context(),
            ['exception' => $e]
        );
    }

    /**
     * Get the default exception context variables for logging.
     */
    protected function exceptionContext(Throwable $e): array
    {
        $context = [];

        if (method_exists($e, 'context')) {
            $context = $e->context();
        }

        foreach ($this->contextCallbacks as $callback) {
            $context = array_merge($context, $callback($e, $context));
        }

        return $context;
    }

    /**
     * Get the default context variables for logging.
     */
    protected function context(): array
    {
        try {
            return array_filter([
                'userId' => Auth::id(),
            ]);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Register a closure that should be used to build exception context data.
     */
    public function buildContextUsing(Closure $contextCallback): static
    {
        $this->contextCallbacks[] = $contextCallback;

        return $this;
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @throws Throwable
     */
    public function render(Request $request, Throwable $e): SymfonyResponse
    {
        $e = $this->mapException($e);

        if (method_exists($e, 'render') && $response = $e->render($request)) {
            return $this->finalizeRenderedResponse(
                $request,
                Router::toResponse($request, $response),
                $e
            );
        }

        if ($e instanceof Responsable) {
            return $this->finalizeRenderedResponse($request, $e->toResponse($request), $e);
        }

        $e = $this->prepareException($e);

        if ($response = $this->renderViaCallbacks($request, $e)) {
            return $this->finalizeRenderedResponse($request, $response, $e);
        }

        return $this->finalizeRenderedResponse($request, match (true) {
            $e instanceof HttpResponseException => $e->getResponse(),
            $e instanceof AuthenticationException => $this->unauthenticated($request, $e),
            $e instanceof ValidationException => $this->convertValidationExceptionToResponse($e, $request),
            default => $this->renderExceptionResponse($request, $e),
        }, $e);
    }

    /**
     * Prepare the final, rendered response to be returned to the browser.
     */
    protected function finalizeRenderedResponse(Request $request, SymfonyResponse $response, Throwable $e): SymfonyResponse
    {
        $response->headers->set('Server', 'Hypervel');

        if ($callbacks = $this->afterResponseCallbacks()) {
            foreach ($callbacks as $callback) {
                $response = $callback($response, $e, $request) ?: $response;
            }
        }

        return $this->finalizeResponseCallback
            ? call_user_func($this->finalizeResponseCallback, $response, $e, $request)
            : $response;
    }

    /**
     * Register a callback to be called after an HTTP error response is rendered.
     */
    public function afterResponse(callable $callback): void
    {
        Context::override('__errors.handler.afterResponse', function ($callbacks) use ($callback) {
            $callbacks = $callbacks ?: [];
            $callbacks[] = $callback;

            return $callbacks;
        });
    }

    /**
     * Get the callbacks that should be called after an HTTP error response is rendered.
     */
    protected function afterResponseCallbacks(): array
    {
        return Context::get('__errors.handler.afterResponse', []);
    }

    /**
     * Prepare the final, rendered response for an exception using the given callback.
     */
    public function respondUsing(callable $callback): static
    {
        $this->finalizeResponseCallback = $callback;

        return $this;
    }

    /**
     * Prepare exception for rendering.
     */
    protected function prepareException(Throwable $e): Throwable
    {
        return match (true) {
            $e instanceof BackedEnumCaseNotFoundException => new NotFoundHttpException($e->getMessage(), $e),
            $e instanceof ModelNotFoundException => new NotFoundHttpException($e->getMessage(), $e),
            $e instanceof AuthorizationException && $e->hasStatus() => new HttpException(
                $e->status(),
                $e->response()?->message() ?: (Response::$statusTexts[$e->status()] ?? 'Whoops, looks like something went wrong.'),
                $e
            ),
            $e instanceof AuthorizationException && ! $e->hasStatus() => new AccessDeniedHttpException($e->getMessage(), $e),
            $e instanceof OriginMismatchException => new HttpException(403, $e->getMessage(), $e),
            $e instanceof TokenMismatchException => new HttpException(419, $e->getMessage(), $e),
            $e instanceof RequestExceptionInterface => new BadRequestHttpException('Bad request.', $e),
            $e instanceof RecordNotFoundException => new NotFoundHttpException('Not found.', $e),
            $e instanceof RecordsNotFoundException => new NotFoundHttpException('Not found.', $e),
            default => $e,
        };
    }

    /**
     * Map the exception using a registered mapper if possible.
     */
    protected function mapException(Throwable $e): Throwable
    {
        if (method_exists($e, 'getInnerException')
            && ($inner = $e->getInnerException()) instanceof Throwable
        ) {
            return $inner;
        }

        foreach ($this->exceptionMap as $class => $mapper) {
            if (is_a($e, $class)) {
                return $mapper($e);
            }
        }

        return $e;
    }

    /**
     * Try to render a response from request and exception via render callbacks.
     */
    protected function renderViaCallbacks(Request $request, Throwable $e): ?SymfonyResponse
    {
        foreach ($this->renderCallbacks as $renderCallback) {
            foreach ($this->firstClosureParameterTypes($renderCallback) as $type) {
                if (is_a($e, $type)) {
                    if ($response = $renderCallback($e, $request)) {
                        return $response;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Render a default exception response if any.
     */
    protected function renderExceptionResponse(Request $request, Throwable $e): Response|JsonResponse|RedirectResponse
    {
        return $this->shouldReturnJson($request, $e)
            ? $this->prepareJsonResponse($request, $e)
            : $this->prepareResponse($request, $e);
    }

    /**
     * Convert an authentication exception into a response.
     */
    protected function unauthenticated(Request $request, AuthenticationException $exception): Response|JsonResponse|RedirectResponse
    {
        return $this->shouldReturnJson($request, $exception)
            ? response()->json(['message' => $exception->getMessage()], 401)
            : redirect()->guest($exception->redirectTo($request) ?? route('login'));
    }

    /**
     * Create a response object from the given validation exception.
     */
    protected function convertValidationExceptionToResponse(ValidationException $e, Request $request): SymfonyResponse
    {
        if ($e->response) {
            return $e->response;
        }

        return $this->shouldReturnJson($request, $e)
            ? $this->invalidJson($request, $e)
            : $this->invalid($request, $e);
    }

    /**
     * Convert a validation exception into a response.
     */
    protected function invalid(Request $request, ValidationException $exception): RedirectResponse
    {
        $redirect = redirect($exception->redirectTo ?? url()->previous());

        if (Context::get(Store::CONTEXT_KEY)) {
            $redirect->withInput(Arr::except($request->input(), $this->dontFlash))
                ->withErrors($exception->errors(), $request->input('_error_bag', $exception->errorBag));
        }

        return $redirect;
    }

    /**
     * Convert a validation exception into a JSON response.
     */
    protected function invalidJson(Request $request, ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], $exception->status);
    }

    /**
     * Determine if the exception handler response should be JSON.
     */
    protected function shouldReturnJson(Request $request, Throwable $e): bool
    {
        return $this->shouldRenderJsonWhenCallback
            ? call_user_func($this->shouldRenderJsonWhenCallback, $request, $e)
            : $request->expectsJson();
    }

    /**
     * Register the callable that determines if the exception handler response should be JSON.
     *
     * @param callable(Request $request, Throwable): bool $callback
     */
    public function shouldRenderJsonWhen(callable $callback): static
    {
        $this->shouldRenderJsonWhenCallback = $callback;

        return $this;
    }

    /**
     * Prepare a response for the given exception.
     */
    protected function prepareResponse(Request $request, Throwable $e): Response|RedirectResponse
    {
        if (! $this->isHttpException($e) && config('app.debug')) {
            return $this->toHypervelResponse($this->convertExceptionToResponse($e), $e)->prepare($request);
        }

        if (! $this->isHttpException($e)) {
            $e = new HttpException(500, $e->getMessage(), $e);
        }

        return $this->toHypervelResponse(
            $this->renderHttpException($e),
            $e
        )->prepare($request);
    }

    /**
     * Create a Symfony response for the given exception.
     */
    protected function convertExceptionToResponse(Throwable $e): SymfonyResponse
    {
        return new SymfonyResponse(
            $this->renderExceptionContent($e),
            $this->isHttpException($e) ? $e->getStatusCode() : 500,
            $this->isHttpException($e) ? $e->getHeaders() : []
        );
    }

    /**
     * Get the response content for the given exception.
     */
    protected function renderExceptionContent(Throwable $e): string
    {
        $debug = config('app.debug');
        try {
            if ($debug && $this->container->bound(ExceptionRenderer::class)) {
                return $this->container->make(ExceptionRenderer::class)->render($e);
            }

            return $this->renderExceptionToHtml($e, $debug);
        } catch (Throwable $e) {
            return $this->renderExceptionToHtml($e, $debug);
        }
    }

    /**
     * Render an exception to a string using the HTML error renderer.
     */
    protected function renderExceptionToHtml(Throwable $e, bool $debug): string
    {
        return $this->container->make(HtmlErrorRenderer::class)
            ->render($e, $debug);
    }

    /**
     * Render the given HttpException.
     *
     * @throws Throwable
     */
    protected function renderHttpException(HttpExceptionInterface $e): SymfonyResponse
    {
        $this->registerErrorViewPaths();

        if ($view = $this->getHttpExceptionView($e)) {
            try {
                return response()->view(
                    $view,
                    [
                        'errors' => new ViewErrorBag(),
                        'exception' => $e,
                    ],
                    $e->getStatusCode(),
                    $e->getHeaders()
                );
            } catch (Throwable $t) {
                config('app.debug') && throw $t;

                $this->report($t);
            }
        }

        return $this->convertExceptionToResponse($e);
    }

    /**
     * Register the error template hint paths.
     */
    protected function registerErrorViewPaths(): void
    {
        (new RegisterErrorViewPaths())();
    }

    /**
     * Get the view used to render HTTP exceptions.
     */
    protected function getHttpExceptionView(HttpExceptionInterface $e): ?string
    {
        $view = 'errors::' . $e->getStatusCode();

        if (view()->exists($view)) {
            return $view;
        }

        $view = substr($view, 0, -2) . 'xx';

        if (view()->exists($view)) {
            return $view;
        }

        return null;
    }

    /**
     * Map the given exception into a Hypervel response.
     */
    protected function toHypervelResponse(SymfonyResponse $response, Throwable $e): Response|RedirectResponse
    {
        if ($response instanceof SymfonyRedirectResponse) {
            $response = new RedirectResponse(
                $response->getTargetUrl(),
                $response->getStatusCode(),
                $response->headers->all()
            );
        } else {
            $response = response(
                $response->getContent(),
                $response->getStatusCode(),
                $response->headers->all()
            );
        }

        return $response->withException($e);
    }

    /**
     * Prepare a JSON response for the given exception.
     */
    protected function prepareJsonResponse(Request $request, Throwable $e): JsonResponse
    {
        return response()->json(
            $this->convertExceptionToArray($e),
            $this->isHttpException($e) ? $e->getStatusCode() : 500,
            $this->isHttpException($e) ? $e->getHeaders() : [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Convert the given exception to an array.
     */
    protected function convertExceptionToArray(Throwable $e): array
    {
        return config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => (new Collection($e->getTrace()))->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error',
        ];
    }

    /**
     * Render an exception to the console.
     */
    public function renderForConsole(OutputInterface $output, Throwable $e): void
    {
        if ($e instanceof CommandNotFoundException) {
            $message = Str::of($e->getMessage())->explode('.')->first();

            if (! empty($alternatives = $e->getAlternatives())) {
                $message .= '. Did you mean one of these?';

                (new Error($output))->render($message);
                (new BulletList($output))->render($alternatives);

                $output->writeln('');
            } else {
                (new Error($output))->render($message);
            }

            return;
        }

        (new ConsoleApplication())->renderThrowable($e, $output);
    }

    /**
     * Do not report duplicate exceptions.
     */
    public function dontReportDuplicates(): static
    {
        $this->withoutDuplicates = true;

        return $this;
    }

    /**
     * Determine if the given exception is an HTTP exception.
     *
     * @phpstan-assert-if-true HttpException $e
     */
    protected function isHttpException(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface;
    }

    /**
     * Map the exception to a log level.
     *
     * @return \Psr\Log\LogLevel::*
     */
    protected function mapLogLevel(Throwable $e): string
    {
        return Arr::first(
            $this->levels,
            fn ($level, $type) => $e instanceof $type,
            LogLevel::ERROR
        );
    }

    /**
     * Create a new logger instance.
     */
    protected function newLogger(): LoggerInterface
    {
        return $this->container->make(LoggerInterface::class);
    }
}
