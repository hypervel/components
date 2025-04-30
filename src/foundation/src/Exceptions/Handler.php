<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions;

use Closure;
use Exception;
use Hyperf\Collection\Arr;
use Hyperf\Context\Context;
use Hyperf\Contract\MessageBag as MessageBagContract;
use Hyperf\Contract\MessageProvider;
use Hyperf\Contract\SessionInterface;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Base\Response as BaseResponse;
use Hyperf\HttpMessage\Exception\HttpException as HyperfHttpException;
use Hyperf\HttpMessage\Upload\UploadedFile;
use Hyperf\Support\MessageBag;
use Hyperf\Validation\ValidationException;
use Hyperf\ViewEngine\ViewErrorBag;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Foundation\Contracts\Application as Container;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionRenderer;
use Hypervel\Foundation\Exceptions\Contracts\ShouldntReport;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\Http\Request;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\HttpMessage\Exceptions\HttpResponseException;
use Hypervel\HttpMessage\Exceptions\NotFoundHttpException;
use Hypervel\Router\Contracts\UrlGenerator as UrlGeneratorContract;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Support\Contracts\Responsable;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Reflector;
use Hypervel\Support\Traits\ReflectsClosures;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionException;
use Throwable;

class Handler extends ExceptionHandler implements ExceptionHandlerContract
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
     * @var array<int, class-string<Throwable>>
     */
    protected array $internalDontReport = [
        AuthenticationException::class,
        AuthorizationException::class,
        HyperfHttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
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
     * Indicates that an exception instance should only be reported once.
     */
    protected bool $withoutDuplicates = false;

    public function __construct(
        protected Container $container
    ) {
        $this->register();
        $this->registerErrorViewPaths();
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
     */
    public function map(callable|string $from, null|Closure|string $to = null): static
    {
        if (is_string($to)) {
            $to = fn ($exception) => new $to('', 0, $exception);
        }

        if (is_callable($from) && is_null($to)) {
            $from = $this->firstClosureParameterType($to = $from);
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
     * Remove the given exception class from the list of exceptions that should be ignored.
     */
    public function stopIgnoring(array|string $exceptions): static
    {
        $exceptions = Arr::wrap($exceptions);

        $this->dontReport = collect($this->dontReport)
            ->reject(fn ($ignored) => in_array($ignored, $exceptions))->values()->all();

        $this->internalDontReport = collect($this->internalDontReport)
            ->reject(fn ($ignored) => in_array($ignored, $exceptions))->values()->all();

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
     * Reports error based on report method on exception or to logger.
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
            $logger = $this->getLogger();
        } catch (Exception) {
            throw $e;
        }

        $level = Arr::first(
            $this->levels,
            fn ($level, $type) => $e instanceof $type,
            LogLevel::ERROR
        );

        $context = $this->buildExceptionContext($e);

        method_exists($logger, $level)
            ? $logger->{$level}($e->getMessage(), $context)
            : $logger->log($level, $e->getMessage(), $context);
    }

    protected function reportedException(Throwable $e): void
    {
        /** @var array<Throwable> $reportedExceptions */
        $reportedExceptions = Context::get('__errors.reportedExceptions', []);
        if (in_array($e, $reportedExceptions)) {
            return;
        }
        $reportedExceptions[] = $e;

        Context::set('__errors.reportedExceptions', $reportedExceptions);
    }

    protected function hasReportedException(Throwable $e): bool
    {
        /** @var array<Throwable> $reportedExceptions */
        $reportedExceptions = Context::get('__errors.reportedExceptions', []);

        return in_array($e, $reportedExceptions);
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

        return false;
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
    public function render(Request $request, Throwable $e): ResponseInterface
    {
        $e = $this->mapException($e);

        if (method_exists($e, 'render') && $response = $e->render($request)) {
            return $this->finalizeRenderedResponse($request, $response, $e);
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
            $e instanceof ValidationException => $this->convertValidationExceptionToResponse($request, $e),
            default => $this->renderExceptionResponse($request, $e),
        }, $e);
    }

    /**
     * Prepare the final, rendered response to be returned to the browser.
     */
    protected function finalizeRenderedResponse(Request $request, ResponseInterface $response, Throwable $e): ResponseInterface
    {
        if ($response instanceof ResponseContract) {
            $response = $response->getPsr7Response();
        }

        return $this->finalizeResponseCallback
            ? call_user_func($this->finalizeResponseCallback, $response, $e, $request)
            : $response;
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
     *
     * @throws ReflectionException
     */
    protected function renderViaCallbacks(Request $request, Throwable $e): ?ResponseInterface
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
    protected function renderExceptionResponse(Request $request, Throwable $e): ResponseInterface
    {
        return $this->shouldReturnJson($request, $e)
            ? $this->prepareJsonResponse($request, $e)
            : $this->prepareResponse($request, $e);
    }

    /**
     * Convert an authentication exception into a response.
     */
    protected function unauthenticated(Request $request, AuthenticationException $exception): ResponseInterface
    {
        return $this->shouldReturnJson($request, $exception)
            ? response()->json(['message' => $exception->getMessage()], 401)
            : redirect($exception->redirectTo($request) ?? route('login'));
    }

    /**
     * Create a response object from the given validation exception.
     */
    protected function convertValidationExceptionToResponse(Request $request, ValidationException $e): ResponseInterface
    {
        return $this->shouldReturnJson($request, $e)
            ? $this->invalidJson($request, $e)
            : $this->invalid($request, $e);
    }

    /**
     * Convert a validation exception into a response.
     */
    protected function invalid(Request $request, ValidationException $exception): ResponseInterface
    {
        $this->withErrors($request, $exception->errors(), $exception->errorBag);

        $urlGenerator = $this->container->get(UrlGeneratorContract::class);
        $redirectUrl = $exception->redirectTo
            ? $urlGenerator->to($exception->redirectTo)
            : $urlGenerator->previous();

        return response()->redirect($redirectUrl);
    }

    /**
     * Flash the exception errors to the session.
     */
    protected function withErrors(Request $request, mixed $provider, string $key = 'default'): void
    {
        if (! Context::get(SessionInterface::class)) {
            return;
        }

        $value = $this->getMessageBag($provider);
        $session = $this->container->get(SessionContract::class);
        $errors = $session->get('errors', new ViewErrorBag());

        if (! $errors instanceof ViewErrorBag) {
            $errors = new ViewErrorBag();
        }
        $session->flash('errors', $errors->put($key, $value));

        $flashInputs = $this->removeFilesFromInput(
            Arr::except($request->all(), $this->dontFlash)
        );
        if ($flashInputs) {
            $session->flashInput($flashInputs);
        }
    }

    /**
     * Remove all uploaded files form the given input array.
     */
    protected function removeFilesFromInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->removeFilesFromInput($value);
            }

            if ($value instanceof UploadedFile) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /**
     * Get the message bag from the given provider.
     */
    protected function getMessageBag(mixed $provider): MessageBagContract
    {
        if ($provider instanceof MessageProvider) {
            return $provider->getMessageBag();
        }

        return new MessageBag((array) $provider);
    }

    /**
     * Convert a validation exception into a JSON response.
     */
    protected function invalidJson(Request $request, ValidationException $exception): ResponseInterface
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
     */
    public function shouldRenderJsonWhen(callable $callback): static
    {
        $this->shouldRenderJsonWhenCallback = $callback;

        return $this;
    }

    /**
     * Prepare a response for the given exception.
     */
    protected function prepareResponse(Request $request, Throwable $e): ResponseInterface
    {
        if (! $this->isHttpException($e) && config('app.debug')) {
            return $this->convertExceptionToResponse($e);
        }

        if (! $this->isHttpException($e)) {
            $e = new HyperfHttpException(500, $e->getMessage(), $e->getCode(), $e);
        }

        return $this->renderHttpException($e);
    }

    /**
     * Create a response for the given exception.
     */
    protected function convertExceptionToResponse(Throwable $e): ResponseInterface
    {
        $response = response()->html(
            $this->renderExceptionContent($e)
        )->withStatus(
            $e instanceof HyperfHttpException ? $e->getStatusCode() : 500
        );

        if ($e instanceof HttpException) {
            foreach ($e->getHeaders() ?: [] as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * Get the response content for the given exception.
     */
    protected function renderExceptionContent(Throwable $e): string
    {
        $debug = config('app.debug');
        try {
            if ($debug && $this->container->bound(ExceptionRenderer::class)) {
                return $this->container->get(ExceptionRenderer::class)->render($e);
            }

            return $this->renderExceptionToHtml($e, $debug);
        } catch (Throwable $e) {
            return $this->renderExceptionToHtml($e, $debug);
        }
    }

    /**
     * Render an exception to a string using Symfony.
     */
    protected function renderExceptionToHtml(Throwable $e, bool $debug): string
    {
        return $this->container->get(HtmlErrorRenderer::class)
            ->render($e, $debug);
    }

    /**
     * Render the given HttpException.
     */
    protected function renderHttpException(HyperfHttpException $e): ResponseInterface
    {
        if ($view = $this->getHttpExceptionView($e)) {
            try {
                return response()->view(
                    $view,
                    [
                        'errors' => new ViewErrorBag(),
                        'exception' => $e,
                    ],
                    $e->getStatusCode(),
                    $e instanceof HttpException ? $e->getHeaders() : []
                );
            } catch (Throwable $t) {
                config('app.debug') && throw $t;

                $this->report($t);
            }
        }

        return $this->convertExceptionToResponse($e);
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
            $e instanceof ModelNotFoundException => new NotFoundHttpException($e->getMessage(), 0, $e),
            $e instanceof AuthorizationException && $e->hasStatus() => new HttpException(
                $e->status(),
                $e->response()?->message() ?: (BaseResponse::getReasonPhraseByCode($e->status()) ?? 'Whoops, looks like something went wrong.'),
                $e->getCode(),
                $e
            ),
            $e instanceof AuthorizationException && ! $e->hasStatus() => new AccessDeniedHttpException($e->getMessage(), 0, $e),
            default => $e,
        };
    }

    /**
     * Register the error template hint paths.
     */
    protected function registerErrorViewPaths()
    {
        (new RegisterErrorViewPaths())();
    }

    /**
     * Get the view used to render HTTP exceptions.
     */
    protected function getHttpExceptionView(HyperfHttpException $e): ?string
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
     * Prepare a JSON response for the given exception.
     */
    protected function prepareJsonResponse(Request $request, Throwable $e): ResponseInterface
    {
        return response()->json(
            $this->convertExceptionToArray($e),
            $e instanceof HyperfHttpException ? $e->getStatusCode() : 500,
            $e instanceof HttpException ? $e->getHeaders() : []
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
            'trace' => collect($e->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error',
        ];
    }

    /**
     * Do not report duplicate exceptions.
     */
    public function dontReportDuplicates()
    {
        $this->withoutDuplicates = true;

        return $this;
    }

    /**
     * Determine if the given exception is an HTTP exception.
     */
    protected function isHttpException(Throwable $e): bool
    {
        return $e instanceof HyperfHttpException;
    }

    /**
     * Create a new logger instance.
     */
    protected function getLogger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->report($throwable);

        return $this->render(
            $this->container->get(Request::class),
            $throwable
        );
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
