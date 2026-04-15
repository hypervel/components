<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Support\Facades\Facade as BaseFacade;
use Sentry\State\HubInterface;

/**
 * @method static \Sentry\ClientInterface|null getClient()
 * @method static \Sentry\EventId|null getLastEventId()
 * @method static \Sentry\State\Scope pushScope()
 * @method static bool popScope()
 * @method static mixed|void withScope(callable $callback)
 * @method static void configureScope(callable $callback)
 * @method static void bindClient(\Sentry\ClientInterface $client)
 * @method static \Sentry\EventId|null captureMessage(string $message, \Sentry\Severity|null $level = null, \Sentry\EventHint|null $hint = null)
 * @method static \Sentry\EventId|null captureException(\Throwable $exception, \Sentry\EventHint|null $hint = null)
 * @method static \Sentry\EventId|null captureEvent(\Sentry\Event $event, \Sentry\EventHint|null $hint = null)
 * @method static \Sentry\EventId|null captureLastError(\Sentry\EventHint|null $hint = null)
 * @method static bool addBreadcrumb(\Sentry\Breadcrumb $breadcrumb)
 * @method static string|null captureCheckIn(string $slug, \Sentry\CheckInStatus $status, int|float|null $duration = null, \Sentry\MonitorConfig|null $monitorConfig = null, string|null $checkInId = null)
 * @method static \Sentry\Integration\IntegrationInterface|null getIntegration(string $className)
 * @method static \Sentry\Tracing\Transaction startTransaction(\Sentry\Tracing\TransactionContext $context, array<string, mixed> $customSamplingContext = [])
 * @method static \Sentry\Tracing\Transaction|null getTransaction()
 * @method static \Sentry\Tracing\Span|null getSpan()
 * @method static \Sentry\State\HubInterface setSpan(\Sentry\Tracing\Span|null $span)
 *
 * @see \Sentry\State\HubInterface
 */
class Facade extends BaseFacade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return HubInterface::class;
    }
}
