<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Testbench\ConfigProviderRegister;
use ReflectionException;
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

        $app->get(ConfigInterface::class)
            ->set('cache', [
                'default' => env('CACHE_DRIVER', 'array'),
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                ],
                'prefix' => env('CACHE_PREFIX', 'hypervel_cache'),
            ]);

        tap($app->get(ConfigInterface::class), function (ConfigInterface $config) {
            $config->set('sentry.before_send', static function (Event $event, ?EventHint $hint) {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            $config->set('sentry.before_send_transaction', static function (Event $event, ?EventHint $hint) {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            foreach ($this->defaultSetupConfig as $key => $value) {
                $config->set($key, $value);
            }

            foreach ($this->setupConfig as $key => $value) {
                $config->set($key, $value);
            }
        });
    }

    protected function setUp(): void
    {
        ConfigProviderRegister::add(SentryServiceProvider::class);
        parent::setUp();
    }

    protected function refreshApplication(): void
    {
        parent::refreshApplication();
        $this->defineEnvironment($this->app);
        $this->app->register(SentryServiceProvider::class, true);
    }

    protected function getSentryHubFromContainer(): HubInterface
    {
        return $this->app->get(HubInterface::class);
    }

    protected function getSentryClientFromContainer(): ClientInterface
    {
        return $this->getSentryHubFromContainer()->getClient();
    }

    protected function getCurrentSentryScope(): Scope
    {
        $hub = $this->getSentryHubFromContainer();

        $method = new ReflectionMethod($hub, 'getScope');
        $method->setAccessible(true);

        return $method->invoke($hub);
    }

    /**
     * @return array<array-key, Breadcrumb>
     * @throws ReflectionException
     */
    protected function getCurrentSentryBreadcrumbs(): array
    {
        $scope = $this->getCurrentSentryScope();

        $property = new ReflectionProperty($scope, 'breadcrumbs');
        $property->setAccessible(true);

        return $property->getValue($scope);
    }

    /**
     * @throws ReflectionException
     */
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

    protected function resetApplicationWithConfig(array $config): void
    {
        $this->setupConfig = $config;

        $this->refreshApplication();
    }

    protected function startTransaction(): Transaction
    {
        $hub = $this->getSentryHubFromContainer();

        $context = new TransactionContext();
        $context->setName('test-transaction');
        $context->setOp('test');

        $transaction = $hub->startTransaction($context);
        $transaction->setSampled(true);

        $this->getCurrentSentryScope()->setSpan($transaction);

        return $transaction;
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
