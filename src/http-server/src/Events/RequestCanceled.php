<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Events;

use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Dispatched when coroutine cancellation stops a received request before its
 * lifecycle, including termination, completes.
 *
 * The response is set only when its send attempt finished. The exception slot
 * keeps an earlier lifecycle failure, as on the other lifecycle events.
 */
class RequestCanceled extends Event
{
    /**
     * Create a new event instance.
     *
     * @param Throwable $cancellation the cancellation, or a failure carrying it, that stopped the lifecycle
     */
    public function __construct(
        Request $request,
        ?Response $response,
        public Throwable $cancellation,
        ?Throwable $exception = null,
        string $server = 'http',
    ) {
        parent::__construct($request, $response, $exception, $server);
    }
}
