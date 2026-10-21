<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

use Hypervel\Http\Client\RequestException;
use Hypervel\Sentry\Util\BoundedOrderedMap;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Throwable;
use WeakReference;

/**
 * Share bounded invocation lookup between a trace's participating coroutines.
 *
 * @internal
 */
class AiTracingState
{
    private const int MAX_TRACKED_INVOCATIONS = 100;

    /** @var BoundedOrderedMap<AiInvocationData> */
    public readonly BoundedOrderedMap $invocations;

    /** @var BoundedOrderedMap<array{span: Span, owner: WeakReference<AiTracingScope>, invocationId: string}> */
    public readonly BoundedOrderedMap $toolInvocations;

    /** @var BoundedOrderedMap<array{span: Span, owner: WeakReference<AiTracingScope>}> */
    public readonly BoundedOrderedMap $embeddingsInvocations;

    /** @var BoundedOrderedMap<array{span: Span, owner: WeakReference<AiTracingScope>}> */
    public readonly BoundedOrderedMap $classifications;

    /**
     * Create bounded maps whose eviction closes spans in their original scopes.
     */
    public function __construct()
    {
        $this->invocations = new BoundedOrderedMap(self::MAX_TRACKED_INVOCATIONS, static function (AiInvocationData $invocation): void {
            $invocation->finish(SpanStatus::deadlineExceeded());
        });

        $finishEvictedSpan = static function (array $invocation): void {
            AiTracingScope::finishSpan($invocation['span'], $invocation['owner']->get(), SpanStatus::deadlineExceeded());
        };

        /** @var BoundedOrderedMap<array{span: Span, owner: WeakReference<AiTracingScope>, invocationId: string}> $toolInvocations */
        $toolInvocations = new BoundedOrderedMap(self::MAX_TRACKED_INVOCATIONS, $finishEvictedSpan);
        $this->toolInvocations = $toolInvocations;

        /** @var BoundedOrderedMap<array{span: Span, owner: WeakReference<AiTracingScope>}> $embeddingsInvocations */
        $embeddingsInvocations = new BoundedOrderedMap(self::MAX_TRACKED_INVOCATIONS, $finishEvictedSpan);
        $this->embeddingsInvocations = $embeddingsInvocations;

        /** @var BoundedOrderedMap<array{span: Span, owner: WeakReference<AiTracingScope>}> $classifications */
        $classifications = new BoundedOrderedMap(self::MAX_TRACKED_INVOCATIONS, $finishEvictedSpan);
        $this->classifications = $classifications;
    }

    /**
     * Resolve an HTTP failure status without subscribing to every outgoing request.
     */
    public static function failureStatus(Throwable $exception): SpanStatus
    {
        do {
            if ($exception instanceof RequestException) {
                return SpanStatus::createFromHttpStatusCode($exception->response->status());
            }
        } while (($exception = $exception->getPrevious()) !== null);

        return SpanStatus::internalError();
    }
}
