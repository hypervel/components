<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\SentryServiceProvider;
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

/**
 * @internal
 * @coversNothing
 */
class SentryTestCase extends \Hypervel\Testbench\TestCase
{
    protected static bool $hasSetupGlobalEventProcessor = false;

    protected array $setupConfig = [];

    protected array $defaultSetupConfig = [];

    /** @var array<int, array{0: Event, 1: null|EventHint}> */
    protected static array $lastSentryEvents = [];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        self::$lastSentryEvents = [];
        $this->setupGlobalEventProcessor();

        tap($app['config'], function (Repository $config) {
            $config->set('sentry.before_send', static function (Event $event, ?EventHint $hint) {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            $config->set('sentry.before_send_transaction', static function (Event $event, ?EventHint $hint) {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            if ($config->get('sentry_test.override_dsn') !== true) {
                $config->set('sentry.dsn', 'https://publickey@sentry.dev/123');
            }

            foreach ($this->defaultSetupConfig as $key => $value) {
                $config->set($key, $value);
            }

            foreach ($this->setupConfig as $key => $value) {
                $config->set($key, $value);
            }
        });

        $app->extend(ExceptionHandler::class, function (ExceptionHandler $handler) {
            return new TestCaseExceptionHandler($handler);
        });
    }

    protected function envWithoutDsnSet(ApplicationContract $app): void
    {
        $app['config']->set('sentry.dsn', null);
        $app['config']->set('sentry_test.override_dsn', true);
    }

    protected function envSamplingAllTransactions(ApplicationContract $app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 1.0);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SentryServiceProvider::class,
        ];
    }

    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [
            'Sentry' => \Hypervel\Sentry\Facade::class,
        ];
    }

    protected function resetApplicationWithConfig(array $config): void
    {
        $this->setupConfig = $config;

        $this->refreshApplication();
    }

    protected function dispatchHypervelEvent(object $event, array $payload = []): void
    {
        $this->app->make('events')->dispatch($event, $payload);
    }

    protected function getSentryHubFromContainer(): HubInterface
    {
        return $this->app->make('sentry');
    }

    protected function getSentryClientFromContainer(): ClientInterface
    {
        return $this->getSentryHubFromContainer()->getClient();
    }

    protected function getCurrentSentryScope(): Scope
    {
        $hub = $this->getSentryHubFromContainer();

        $method = new ReflectionMethod($hub, 'getScope');

        return $method->invoke($hub);
    }

    /**
     * @return array<array-key, Breadcrumb>
     */
    protected function getCurrentSentryBreadcrumbs(): array
    {
        $scope = $this->getCurrentSentryScope();

        $property = new ReflectionProperty($scope, 'breadcrumbs');

        return $property->getValue($scope);
    }

    protected function getLastSentryBreadcrumb(): ?Breadcrumb
    {
        $breadcrumbs = $this->getCurrentSentryBreadcrumbs();

        if (empty($breadcrumbs)) {
            return null;
        }

        return end($breadcrumbs);
    }

    protected function getLastSentryEvent(): ?Event
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[0];
    }

    protected function getLastEventSentryHint(): ?EventHint
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[1];
    }

    /** @return array<int, array{0: Event, 1: null|EventHint}> */
    protected function getCapturedSentryEvents(): array
    {
        return self::$lastSentryEvents;
    }

    protected function assertSentryEventCount(int $count): void
    {
        $this->assertCount($count, array_filter(self::$lastSentryEvents, static function (array $event) {
            return $event[0]->getType() === EventType::event();
        }));
    }

    protected function assertSentryCheckInCount(int $count): void
    {
        $this->assertCount($count, array_filter(self::$lastSentryEvents, static function (array $event) {
            return $event[0]->getType() === EventType::checkIn();
        }));
    }

    protected function assertSentryTransactionCount(int $count): void
    {
        $this->assertCount($count, array_filter(self::$lastSentryEvents, static function (array $event) {
            return $event[0]->getType() === EventType::transaction();
        }));
    }

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

    protected function setupGlobalEventProcessor(): void
    {
        if (self::$hasSetupGlobalEventProcessor) {
            return;
        }

        Scope::addGlobalEventProcessor(static function (Event $event, ?EventHint $hint) {
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
