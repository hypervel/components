<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pipeline;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Pipeline\PipelineServiceProvider;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Pipeline;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class PipelineTransactionTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            PipelineServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function testPipelineTransaction()
    {
        Event::fake();

        $result = Pipeline::withinTransaction()
            ->send('some string')
            ->through([
                fn ($value, $next) => $next($value),
                fn ($value, $next) => $next($value),
            ])
            ->thenReturn();

        $this->assertEquals('some string', $result);
        Event::assertDispatchedTimes(TransactionBeginning::class, 1);
        Event::assertDispatchedTimes(TransactionCommitted::class, 1);
    }

    #[DataProvider('transactionConnectionDataProvider')]
    public function testConnection($connection, $connectionName)
    {
        Event::fake();
        config(['database.connections.testing2' => config('database.connections.testing')]);
        config(['database.default' => 'testing2']);

        $result = Pipeline::withinTransaction($connection)
            ->send('some string')
            ->through([
                function ($value, $next) {
                    return $next($value);
                },
            ])
            ->thenReturn();

        $this->assertEquals('some string', $result);
        Event::dispatched(TransactionBeginning::class, function (TransactionBeginning $event) use ($connectionName) {
            return $event->connection === $connectionName;
        });
    }

    public static function transactionConnectionDataProvider(): array
    {
        return [
            'unit enum' => [EnumForPipelineTransactionTest::DEFAULT, 'testing'],
            'string' => ['testing', 'testing'],
            'null' => [null, 'testing2'],
        ];
    }

    public function testExceptionThrownRollsBackTransaction()
    {
        Event::fake();

        $finallyRan = false;
        try {
            Pipeline::withinTransaction()
                ->send('some string')
                ->through([
                    function ($value, $next) {
                        throw new Exception('I was thrown');
                    },
                ])
                ->finally(function () use (&$finallyRan) {
                    $finallyRan = true;
                })
                ->thenReturn();
            $this->fail('No exception was thrown');
        } catch (Exception) {
        }

        $this->assertTrue($finallyRan);
        Event::assertDispatched(TransactionBeginning::class);
        Event::assertDispatched(TransactionRolledBack::class);
    }
}

enum EnumForPipelineTransactionTest: string
{
    case DEFAULT = 'testing';
}
