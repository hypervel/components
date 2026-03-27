<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\CustomPayloadTest;

use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Queue;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class CustomPayloadTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [QueueServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    #[DataProvider('websites')]
    public function testCustomPayloadGetsClearedForEachDataProvider(string $websites)
    {
        $dispatcher = $this->app->make(QueueingDispatcher::class);

        $dispatcher->dispatchToQueue(new MyJob());
    }

    public static function websites()
    {
        yield ['hypervel.com'];

        yield ['blog.hypervel.com'];
    }
}

class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('one.time.password', fn () => random_int(1, 10));

        Queue::createPayloadUsing(function () {
            $password = $this->app->make('one.time.password');

            $this->app->offsetUnset('one.time.password');

            return ['password' => $password];
        });
    }
}

class MyJob implements ShouldQueue
{
    public string $connection = 'sync';

    public function handle(): void
    {
    }
}
