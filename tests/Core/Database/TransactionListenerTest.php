<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database;

use Hypervel\Database\TransactionManager;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class TransactionListenerTest extends TestCase
{
    public function testCallbacksFollowDatabaseTransactions()
    {
        $manager = $this->app->get(TransactionManager::class);
        $calls = [];

        DB::transaction(function () use ($manager, &$calls) {
            $manager->addCallback(function () use (&$calls) {
                $calls[] = 'outer';
            });

            try {
                DB::transaction(function () use ($manager, &$calls) {
                    $manager->addCallback(function () use (&$calls) {
                        $calls[] = 'rolled back savepoint';
                    });

                    throw new RuntimeException();
                });
            } catch (RuntimeException) {
            }

            $this->assertSame([], $calls);
        });

        $this->assertSame(['outer'], $calls);
    }
}
