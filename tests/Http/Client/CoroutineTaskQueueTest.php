<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

class CoroutineTaskQueueTest extends TestCase
{
    public function testConcurrentRequestsAndPromiseChainsRunOnlyTheirOwnCallbacks(): void
    {
        // The transport yields and is called from a then() callback, as the AWS SDK's signer calls it.
        $stack = HandlerStack::create(static function (RequestInterface $request): PromiseInterface {
            usleep(2_000);

            return Create::promiseFor(new Response(200, [], $request->getUri()->getPath()));
        });
        $stack->push(static fn (callable $handler): callable => static fn (RequestInterface $request, array $options): PromiseInterface => Create::promiseFor(null)->then(
            static fn (): PromiseInterface => $handler($request, $options)
        ));
        $client = new Client(['handler' => $stack, 'base_uri' => 'http://hypervel.test']);

        $send = static fn (string $path): callable => static fn (): string => (string) $client->get($path)->getBody();
        $chain = static fn (int $value): callable => static function () use ($value): int {
            $promise = Create::promiseFor($value)->then(static function (int $value): int {
                usleep(1_000);

                return $value * 10;
            });
            usleep(1_000);

            return $promise->wait();
        };

        $this->assertEquals([
            'first request' => '/first',
            'second request' => '/second',
            'third request' => '/third',
            'first chain' => 10,
            'second chain' => 20,
            'third chain' => 30,
        ], parallel([
            'first request' => $send('/first'),
            'first chain' => $chain(1),
            'second request' => $send('/second'),
            'second chain' => $chain(2),
            'third request' => $send('/third'),
            'third chain' => $chain(3),
        ]));
    }

    public function testCopiedContextDoesNotShareTheParentsTasks(): void
    {
        $ran = false;
        $promise = Create::promiseFor(1)->then(static function (int $value) use (&$ran): int {
            $ran = true;

            return $value + 1;
        });

        [$childSawTasks] = parallel([static function (): bool {
            $queue = Utils::queue();
            $sawTasks = ! $queue->isEmpty();
            $queue->run();

            return $sawTasks;
        }], copyContext: true);

        $this->assertFalse($childSawTasks);
        $this->assertFalse($ran);
        $this->assertSame(2, $promise->wait());
        $this->assertTrue($ran);
    }

    public function testTasksOfACanceledCoroutineNeverRunElsewhere(): void
    {
        $ran = false;
        $child = go(static function () use (&$ran): void {
            Create::promiseFor(null)->then(static function () use (&$ran): void {
                $ran = true;
            });
            usleep(1_000_000);
        });

        EngineCoroutine::cancelById($child, throwException: true);
        Coroutine::join([$child]);

        $this->assertSame(2, Create::promiseFor(1)->then(static fn (int $value): int => $value + 1)->wait());
        $this->assertFalse($ran);
    }

    public function testPromiseSettledByItsWaitFunctionCanBeWaitedOnInAnotherCoroutine(): void
    {
        $promise = new Promise(static function () use (&$promise): void {
            usleep(1_000);
            $promise->resolve(1);
        });
        $chained = $promise->then(static fn (int $value): int => $value + 1);

        $this->assertSame([2], parallel([static fn (): int => $chained->wait()]));
    }
}
