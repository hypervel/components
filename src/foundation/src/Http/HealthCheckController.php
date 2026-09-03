<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Http\Request;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The shared response and cancellation boundary for framework health routes.
 */
class HealthCheckController
{
    /**
     * Create a new health check controller instance.
     */
    public function __construct(
        protected Application $app,
        protected Dispatcher $events,
        protected ExceptionHandler $exceptions,
        protected ResponseFactory $responses,
        protected ViewFactory $views,
    ) {
    }

    /**
     * Run the application health check.
     */
    public function __invoke(Request $request): Response
    {
        $health = 'up';

        try {
            if ($this->events->hasListeners(DiagnosingHealth::class)) {
                $this->events->dispatch(new DiagnosingHealth);
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            if ($this->app->hasDebugModeEnabled()) {
                throw $throwable;
            }

            $this->exceptions->report($throwable);

            $health = 'down';
        }

        $status = $health === 'up' ? 200 : 500;

        if ($request->expectsJson()) {
            return $this->responses->json(['status' => $health], $status);
        }

        return $this->responses->make($this->views->file(
            __DIR__ . '/../resources/health-up.blade.php',
            ['request' => $request, 'status' => $health],
        ), $status);
    }
}
