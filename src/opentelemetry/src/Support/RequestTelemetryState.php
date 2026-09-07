<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Http\Request;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

class RequestTelemetryState implements NonCopyableContext
{
    private const string CONTEXT_KEY = '__opentelemetry.request';

    public bool $completed = false;

    public bool $userResolved = false;

    /** @var array<string, null|array|bool|float|int|string> */
    public array $userAttributes = [];

    /**
     * Create request-scoped telemetry state.
     *
     * @param array<string, null|array|bool|float|int|string> $activeRequestAttributes
     */
    public function __construct(
        public Request $request,
        public int $startedAt,
        public ?SpanInterface $span,
        public ?ContextInterface $context,
        public ?ScopeInterface $scope,
        public ?LogContextScope $logContextScope,
        public array $activeRequestAttributes,
        public bool $activeRequestRecorded,
    ) {
    }

    /**
     * Store state for the current request coroutine.
     */
    public static function set(self $state): void
    {
        CoroutineContext::set(self::CONTEXT_KEY, $state);
    }

    /**
     * Return state for the current request coroutine.
     */
    public static function current(): ?self
    {
        $state = CoroutineContext::get(self::CONTEXT_KEY);

        return $state instanceof self ? $state : null;
    }
}
