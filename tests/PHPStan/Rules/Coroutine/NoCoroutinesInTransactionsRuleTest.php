<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\Concurrency\ConcurrencyManager;
use Hypervel\Concurrency\SyncDriver;
use Hypervel\Container\Container;
use Hypervel\PHPStan\Rules\Coroutine\NoCoroutinesInTransactionsRule;
use Hypervel\Tests\PHPStan\Rules\Coroutine\Data\ExtendedConcurrencyManager;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoCoroutinesInTransactionsRule> */
class NoCoroutinesInTransactionsRuleTest extends RuleTestCase
{
    public const string MESSAGE = "A coroutine started inside a database transaction runs outside it: the child borrows its own pooled connection.\n    💡 Start the coroutine after the transaction commits, or run the work in the transaction's own coroutine.";

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoCoroutinesInTransactionsRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/coroutines-in-transactions.php'], [
            [self::MESSAGE, 23],
            [self::MESSAGE, 24],
            [self::MESSAGE, 25],
            [self::MESSAGE, 26],
            [self::MESSAGE, 27],
            [self::MESSAGE, 28],
            [self::MESSAGE, 41],
            [self::MESSAGE, 48],
            [self::MESSAGE, 51],
            [self::MESSAGE, 52],
            [self::MESSAGE, 67],
            [self::MESSAGE, 74],
            [self::MESSAGE, 79],
            [self::MESSAGE, 87],
            [self::MESSAGE, 90],
            [self::MESSAGE, 97],
        ]);
    }

    public function testCustomCoroutineDriverIsUnknown(): void
    {
        $manager = Container::getInstance()->make(ConcurrencyManager::class);
        $manager->extend('coroutine', fn (): SyncDriver => new SyncDriver);

        try {
            $this->analyse([__DIR__ . '/data/concurrency-run.php'], []);
        } finally {
            (function (): void {
                unset($this->customCreators['coroutine']);
            })->call($manager);
        }
    }

    public function testExtendedManagerIsUnknown(): void
    {
        $container = Container::getInstance();
        $manager = $container->make(ConcurrencyManager::class);
        $container->instance(ConcurrencyManager::class, new ExtendedConcurrencyManager($container));

        try {
            $this->analyse([__DIR__ . '/data/concurrency-run.php'], []);
        } finally {
            $container->instance(ConcurrencyManager::class, $manager);
        }
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
