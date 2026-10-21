<?php

declare(strict_types=1);

use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Support\Facades\Response;

use function PHPStan\Testing\assertType;

function eventStreamResponseTypes(ResponseFactory $factory, ResponseFactoryContract $contract): void
{
    $events = static function (): Generator {
        yield 'message';
    };

    assertType(IterableStreamedResponse::class, $factory->eventStream($events)->cancelOnDisconnect());
    assertType(IterableStreamedResponse::class, $contract->eventStream($events)->cancelOnDisconnect());
    assertType(IterableStreamedResponse::class, response()->eventStream($events)->cancelOnDisconnect());
    assertType(IterableStreamedResponse::class, Response::eventStream($events)->cancelOnDisconnect());
}
