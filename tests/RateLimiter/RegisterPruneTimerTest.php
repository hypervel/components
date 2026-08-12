<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Config\Repository;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\Timer;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\Listeners\RegisterPruneTimer;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\SwooleStore;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Server as SwooleServer;
use Throwable;

class RegisterPruneTimerTest extends TestCase
{
    public function testRegistersPruneTimersForEverySwooleStoreOnWorkerZero(): void
    {
        $firstStore = m::mock(SwooleStore::class);
        $firstStore->shouldReceive('maintain')->once()->andReturn(2);
        $secondStore = m::mock(SwooleStore::class);
        $secondStore->shouldReceive('maintain')->once()->andReturn(3);
        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('store')->once()->with('first')->andReturn($this->limiter($firstStore));
        $rateLimiter->shouldReceive('store')->once()->with('second')->andReturn($this->limiter($secondStore));
        $timer = new FakeRateLimiterTimer;

        (new RegisterPruneTimer($this->config([
            'first' => ['driver' => 'swoole', 'prune_interval' => 5],
            'redis' => ['driver' => 'redis'],
            'second' => ['driver' => 'swoole', 'prune_interval' => 30],
        ]), $rateLimiter, $timer))->handle($this->workerEvent(workerId: 0));

        $this->assertSame([5.0, 30.0], array_column($timer->ticks, 'seconds'));
        $this->assertSame(2, $timer->ticks[0]['callback']());
        $this->assertSame(3, $timer->ticks[1]['callback']());
    }

    public function testDoesNotRegisterATimerOnOtherWorkers(): void
    {
        $timer = new FakeRateLimiterTimer;

        (new RegisterPruneTimer($this->config([]), m::mock(RateLimiter::class), $timer))
            ->handle($this->workerEvent(workerId: 1));

        $this->assertSame([], $timer->ticks);
    }

    public function testDoesNotRegisterATimerOnTaskWorkers(): void
    {
        $timer = new FakeRateLimiterTimer;

        (new RegisterPruneTimer($this->config([]), m::mock(RateLimiter::class), $timer))
            ->handle($this->workerEvent(workerId: 0, taskworker: true));

        $this->assertSame([], $timer->ticks);
    }

    public function testRollsBackEarlierTimersWhenRegistrationFails(): void
    {
        $firstStore = m::mock(SwooleStore::class);
        $secondStore = m::mock(SwooleStore::class);
        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('store')->once()->with('first')->andReturn($this->limiter($firstStore));
        $rateLimiter->shouldReceive('store')->once()->with('second')->andReturn($this->limiter($secondStore));
        $failure = new RuntimeException('Timer registration failed.');
        $timer = new FakeRateLimiterTimer([41, $failure]);

        try {
            (new RegisterPruneTimer($this->config([
                'first' => ['driver' => 'swoole', 'prune_interval' => 5],
                'second' => ['driver' => 'swoole', 'prune_interval' => 30],
            ]), $rateLimiter, $timer))->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected timer registration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([41], $timer->cleared);
    }

    /**
     * @param array<string, mixed> $intervalConfig
     */
    #[DataProvider('invalidIntervals')]
    public function testRejectsInvalidIntervalsBeforeTimerRegistration(array $intervalConfig): void
    {
        $timer = new FakeRateLimiterTimer;

        try {
            (new RegisterPruneTimer($this->config([
                'invalid' => ['driver' => 'swoole', ...$intervalConfig],
            ]), m::mock(RateLimiter::class), $timer))->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected invalid timer configuration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'rate-limiter.stores.invalid.prune_interval',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $timer->ticks);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidIntervals(): array
    {
        return [
            'missing' => [[]],
            'wrong type' => [['prune_interval' => '60']],
            'zero' => [['prune_interval' => 0]],
            'negative' => [['prune_interval' => -1]],
        ];
    }

    public function testRejectsAnInvalidLaterStoreBeforeRegisteringEarlierTimers(): void
    {
        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->shouldNotReceive('store');
        $timer = new FakeRateLimiterTimer;

        try {
            (new RegisterPruneTimer($this->config([
                'first' => ['driver' => 'swoole', 'prune_interval' => 5],
                'second' => ['driver' => 'swoole', 'prune_interval' => 0],
            ]), $rateLimiter, $timer))->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected invalid timer configuration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'rate-limiter.stores.second.prune_interval',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $timer->ticks);
    }

    /**
     * @param array<string, array<string, mixed>> $stores
     */
    private function config(array $stores): Repository
    {
        return new Repository([
            'rate-limiter' => [
                'stores' => $stores,
            ],
        ]);
    }

    private function limiter(SwooleStore $store): Limiter
    {
        $limiter = m::mock(Limiter::class);
        $limiter->shouldReceive('getStore')->once()->andReturn($store);

        return $limiter;
    }

    private function workerEvent(int $workerId, bool $taskworker = false): AfterWorkerStart
    {
        $server = m::mock(SwooleServer::class);
        $server->taskworker = $taskworker;

        return new AfterWorkerStart($server, $workerId);
    }
}

class FakeRateLimiterTimer extends Timer
{
    /** @var list<array{seconds: float, callback: callable, identifier: string}> */
    public array $ticks = [];

    /** @var list<int> */
    public array $cleared = [];

    /**
     * @param list<int|Throwable> $results
     */
    public function __construct(protected array $results = [])
    {
        parent::__construct();
    }

    public function tick(
        float $seconds,
        callable $callback,
        string $identifier = Constants::WORKER_EXIT,
    ): int {
        $this->ticks[] = compact('seconds', 'callback', 'identifier');

        if ($this->results !== []) {
            $result = array_shift($this->results);

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }

        return count($this->ticks);
    }

    public function clear(int $timerId): void
    {
        $this->cleared[] = $timerId;
    }
}
