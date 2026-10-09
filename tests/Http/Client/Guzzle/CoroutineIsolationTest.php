<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Utils;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    /**
     * Configure independent tenant databases with the production connection resolver.
     */
    protected function defineEnvironment(Application $app): void
    {
        foreach (['first', 'second'] as $tenant) {
            $app->make('config')->set("database.connections.{$tenant}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'pool' => ['testing_enabled' => true],
            ]);
        }
    }

    public function testYieldingCallbacksResolveTheirOwnRequestAndDatabaseTransaction(): void
    {
        $callbacks = $observed = $expected = [];

        foreach (['first', 'second'] as $tenant) {
            $callbacks[] = static function () use ($tenant, &$observed, &$expected): void {
                RequestContext::set(Request::create('/' . $tenant));
                DB::setDefaultConnection($tenant);
                $connection = DB::connection();
                $connection->statement('create table messages (tenant varchar(255))');
                $connection->beginTransaction();
                $connection->table('messages')->insert(['tenant' => $tenant]);
                $expected[$tenant] = [Coroutine::id(), '/' . $tenant, $connection, 1, $tenant];

                try {
                    $promise = Create::promiseFor(null);
                    foreach ([true, false] as $yield) {
                        $promise->then(static function () use ($tenant, $yield, &$observed): void {
                            if ($yield) {
                                usleep(1000);
                            }

                            $resolved = DB::connection();
                            $observed[$tenant][] = [
                                $yield,
                                Coroutine::id(),
                                RequestContext::get()->getPathInfo(),
                                $resolved,
                                $resolved->transactionLevel(),
                                $resolved->table('messages')->value('tenant'),
                            ];
                        });
                    }
                    Utils::queue()->run();
                } finally {
                    $connection->rollBack();
                }
            };
        }

        parallel($callbacks);

        foreach ($expected as $tenant => $snapshot) {
            $this->assertSame([[true, ...$snapshot], [false, ...$snapshot]], $observed[$tenant]);
            $this->assertSame(0, DB::connection($tenant)->table('messages')->count());
        }
    }
}
