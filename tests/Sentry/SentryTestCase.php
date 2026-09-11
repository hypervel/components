<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Support\Arr;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sentry\Fixtures\TestCaseExceptionHandler;
use ReflectionMethod;
use ReflectionProperty;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventType;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class SentryTestCase extends TestCase
{
    protected static bool $hasSetupGlobalEventProcessor = false;

    protected array $setupConfig = [];

    protected array $defaultSetupConfig = [];

    /** @var array<int, array{0: Event, 1: null|EventHint}> */
    protected static array $lastSentryEvents = [];

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        self::$lastSentryEvents = [];
        $this->setupGlobalEventProcessor();

        tap($app->make('config'), function (Repository $config): void {
            $config->set('sentry.before_send', static function (Event $event, ?EventHint $hint): null {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            $config->set('sentry.before_send_transaction', static function (Event $event, ?EventHint $hint): null {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });
        });

        $app->extend(ExceptionHandler::class, function (ExceptionHandler $handler): TestCaseExceptionHandler {
            return new TestCaseExceptionHandler($handler);
        });
    }

    /**
     * Configure the application without a DSN.
     */
    protected function envWithoutDsnSet(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('sentry.dsn', null);
        $config->set('sentry_test.override_dsn', true);
    }

    /**
     * Configure sampling for all transactions.
     */
    protected function envSamplingAllTransactions(ApplicationContract $app): void
    {
        $app->make('config')->set('sentry.traces_sample_rate', 1.0);
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        $config = $app->make('config');

        if ($config->get('sentry_test.override_dsn') !== true) {
            $config->set('sentry.dsn', 'https://publickey@sentry.dev/123');
        }

        foreach ($this->defaultSetupConfig as $key => $value) {
            $config->set($key, $value);
        }

        foreach ($this->setupConfig as $key => $value) {
            $config->set($key, $value);
        }

        return [
            SentryServiceProvider::class,
        ];
    }

    /**
     * Get the package aliases.
     */
    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [
            'Sentry' => \Hypervel\Sentry\Facade::class,
        ];
    }

    /**
     * Reload the application with the given configuration.
     */
    protected function resetApplicationWithConfig(array $config): void
    {
        $this->setupConfig = $config;

        $this->reloadApplication();
    }

    /**
     * Return the complete shipped Sentry config with test-specific overrides.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function sentryConfigWith(array $overrides): array
    {
        $config = config()->array('sentry');

        foreach ($overrides as $key => $value) {
            Arr::set($config, $key, $value);
        }

        return $config;
    }

    /**
     * Dispatch a framework event.
     */
    protected function dispatchHypervelEvent(object $event, array $payload = []): void
    {
        $this->app->make('events')->dispatch($event, $payload);
    }

    /**
     * Get the Sentry hub.
     */
    protected function getSentryHubFromContainer(): HubInterface
    {
        return $this->app->make('sentry');
    }

    /**
     * Get the Sentry client.
     */
    protected function getSentryClientFromContainer(): ClientInterface
    {
        return $this->getSentryHubFromContainer()->getClient();
    }

    /**
     * Get the current Sentry scope.
     */
    protected function getCurrentSentryScope(): Scope
    {
        $hub = $this->getSentryHubFromContainer();

        $method = new ReflectionMethod($hub, 'getScope');

        return $method->invoke($hub);
    }

    /**
     * Get the current Sentry breadcrumbs.
     *
     * @return array<array-key, Breadcrumb>
     */
    protected function getCurrentSentryBreadcrumbs(): array
    {
        $scope = $this->getCurrentSentryScope();

        $property = new ReflectionProperty($scope, 'breadcrumbs');

        return $property->getValue($scope);
    }

    /**
     * Get the last Sentry breadcrumb.
     */
    protected function getLastSentryBreadcrumb(): ?Breadcrumb
    {
        $breadcrumbs = $this->getCurrentSentryBreadcrumbs();

        if (empty($breadcrumbs)) {
            return null;
        }

        return end($breadcrumbs);
    }

    /**
     * Get the last Sentry event.
     */
    protected function getLastSentryEvent(): ?Event
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[0];
    }

    /**
     * Get the last Sentry event hint.
     */
    protected function getLastEventSentryHint(): ?EventHint
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[1];
    }

    /**
     * Get the captured Sentry events.
     *
     * @return array<int, array{0: Event, 1: null|EventHint}>
     */
    protected function getCapturedSentryEvents(): array
    {
        return self::$lastSentryEvents;
    }

    /**
     * Return captured Sentry events of the given type.
     *
     * @return list<array{0: Event, 1: null|EventHint}>
     */
    protected function getCapturedSentryEventsOfType(EventType $eventType): array
    {
        return array_values(array_filter(
            self::$lastSentryEvents,
            static fn (array $event): bool => $event[0]->getType() === $eventType,
        ));
    }

    /**
     * Assert the number of captured events.
     */
    protected function assertSentryEventCount(int $count): void
    {
        $this->assertCount($count, $this->getCapturedSentryEventsOfType(EventType::event()));
    }

    /**
     * Assert the number of captured check-ins.
     */
    protected function assertSentryCheckInCount(int $count): void
    {
        $this->assertCount($count, $this->getCapturedSentryEventsOfType(EventType::checkIn()));
    }

    /**
     * Assert the number of captured transactions.
     */
    protected function assertSentryTransactionCount(int $count): void
    {
        $this->assertCount($count, $this->getCapturedSentryEventsOfType(EventType::transaction()));
    }

    /**
     * Start a sampled transaction.
     */
    protected function startTransaction(): Transaction
    {
        $hub = $this->getSentryHubFromContainer();

        $transaction = $hub->startTransaction(new TransactionContext);
        $transaction->setSampled(true);

        if ($transaction->getSpanRecorder() === null) {
            $transaction->initSpanRecorder();
        }

        $this->getCurrentSentryScope()->setSpan($transaction);

        return $transaction;
    }

    /**
     * Register the global test event processor once.
     */
    protected function setupGlobalEventProcessor(): void
    {
        if (self::$hasSetupGlobalEventProcessor) {
            return;
        }

        Scope::addGlobalEventProcessor(static function (Event $event, ?EventHint $hint): ?Event {
            // Regular events and transactions are handled by the `before_send` and `before_send_transaction` callbacks
            if (in_array($event->getType(), [EventType::event(), EventType::transaction()], true)) {
                return $event;
            }

            self::$lastSentryEvents[] = [$event, $hint];

            return null;
        });

        self::$hasSetupGlobalEventProcessor = true;
    }
}
