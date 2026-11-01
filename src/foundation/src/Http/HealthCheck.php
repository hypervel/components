<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthCheck
{
    /**
     * Handle the incoming request.
     *
     * @throws Throwable
     */
    public function __invoke(Request $request): Response
    {
        // Empty diagnosis messages must still produce a failure response.
        $health = 'up';

        try {
            if (Event::hasListeners(DiagnosingHealth::class)) {
                Event::dispatch(new DiagnosingHealth);
            }
        } catch (Throwable $throwable) {
            // Listener cleanup can wrap cancellation; it must still leave the health check.
            if (Coroutine::causedByCancellation($throwable) || app()->hasDebugModeEnabled()) {
                throw $throwable;
            }

            report($throwable);

            $health = 'down';
        }

        $status = $health === 'up' ? 200 : 500;

        if ($request->expectsJson()) {
            return response()->json(['status' => $health], $status);
        }

        return response(View::file(
            __DIR__ . '/../resources/health-up.blade.php',
            ['request' => $request, 'status' => $health],
        ), status: $status);
    }
}
