<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Di\Aop\ProxyMethod;
use Hypervel\Http\Exceptions\CoroutineOwnershipException;
use Hypervel\Testbench\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class PromiseOwnershipTest extends TestCase
{
    public function testTheGeneratedConstructorAndOperationsAreIntercepted(): void
    {
        foreach (['__construct', 'then', 'wait', 'cancel', 'resolve', 'reject'] as $method) {
            $this->assertCount(1, (new ReflectionMethod(Promise::class, $method))->getAttributes(ProxyMethod::class));
        }

        $promise = new Promise;
        $result = parallel([static function () use ($promise): string {
            try {
                $promise->resolve('foreign');
            } catch (CoroutineOwnershipException $exception) {
                return $exception->getMessage();
            }

            return 'allowed';
        }]);

        $this->assertStringContainsString('cannot call [resolve] on a pending promise', $result[0]);
        $this->assertSame(PromiseInterface::PENDING, $promise->getState());
        $promise->resolve('owner');
        $this->assertSame('owner', $promise->wait());
    }

    #[DataProvider('foreignOperations')]
    public function testForeignPendingOperationsFailBeforeSideEffects(string $operation): void
    {
        $sideEffects = [];
        $promise = new Promise(
            static function () use (&$sideEffects): void { $sideEffects[] = 'wait'; },
            static function () use (&$sideEffects): void { $sideEffects[] = 'cancel'; },
        );

        $results = parallel([static function () use ($promise, $operation, &$sideEffects): ?CoroutineOwnershipException {
            try {
                match ($operation) {
                    'then' => $promise->then(static function () use (&$sideEffects): void { $sideEffects[] = 'callback'; }),
                    'wait' => $promise->wait(false),
                    'cancel' => $promise->cancel(),
                    'resolve' => $promise->resolve('value'),
                    'reject' => $promise->reject('reason'),
                    'adopt' => (new Promise)->resolve($promise),
                };
            } catch (CoroutineOwnershipException $exception) {
                return $exception;
            }

            return null;
        }]);

        $this->assertInstanceOf(CoroutineOwnershipException::class, $results[0]);
        $this->assertSame([], $sideEffects);
        $this->assertSame(PromiseInterface::PENDING, $promise->getState());
        $promise->resolve('done');
    }

    /**
     * Provide operations that can execute or alter pending work.
     */
    public static function foreignOperations(): array
    {
        return array_map(static fn (string $operation): array => [$operation], [
            'then', 'wait', 'cancel', 'resolve', 'reject', 'adopt',
        ]);
    }

    public function testCompletedFulfillmentAndRejectionCanBeReusedInOtherCoroutines(): void
    {
        $fulfilled = new Promise;
        $fulfilled->resolve('done');
        $failure = new RuntimeException('failed');
        $rejected = new Promise;
        $rejected->reject($failure);

        $results = parallel([static function () use ($fulfilled, $rejected): array {
            return [
                $fulfilled->then(static fn (string $value): string => $value . '!')->wait(),
                $rejected->otherwise(static fn (RuntimeException $exception): RuntimeException => $exception)->wait(),
            ];
        }]);

        $this->assertSame(['done!', $failure], $results[0]);
    }

    public function testAdoptedPendingChainsRemainOwnedUntilTheirValueCompletes(): void
    {
        $inner = new Promise;
        $middle = new Promise;
        $outer = new Promise;
        $middle->resolve($inner);
        $outer->resolve($middle);

        $this->assertSame(PromiseInterface::FULFILLED, $outer->getState());
        $blocked = parallel([static function () use ($outer): bool {
            try {
                $outer->then(static fn (mixed $value): mixed => $value);
            } catch (CoroutineOwnershipException) {
                return true;
            }

            return false;
        }]);
        $this->assertTrue($blocked[0]);

        $inner->resolve('done');
        $this->assertSame(['done'], parallel([static fn (): mixed => $outer->wait()]));
    }

    public function testFailedSelfResolutionDoesNotLeaveAdoptionMetadata(): void
    {
        $promise = new Promise;
        try {
            $promise->resolve($promise);
            $this->fail('Self-resolution was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('itself', $exception->getMessage());
        }

        $this->assertSame(PromiseInterface::PENDING, $promise->getState());
        $promise->resolve('done');
        $this->assertSame(['done'], parallel([static fn (): mixed => $promise->wait()]));
    }
}
