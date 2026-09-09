<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Contracts\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class PoolManagerTest extends TestCase
{
    protected PoolManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new PoolManager;
    }

    protected function tearDownInCoroutine(): void
    {
        $this->manager->purgeAll();
    }

    public function testPoolBuildsDefinitionsFromNamesAndOptions(): void
    {
        $defaultPool = $this->manager->pool(
            'app:reports',
            static fn (): object => new stdClass,
        );
        $configuredPool = $this->manager->pool(
            'app:exports',
            static fn (): object => new stdClass,
            ['max_objects' => 20, 'pool_idle_timeout' => null],
        );

        $defaultDefinition = $this->manager->getDefinition('app:reports');
        $configuredDefinition = $this->manager->getDefinition('app:exports');

        $this->assertInstanceOf(PoolDefinition::class, $defaultDefinition);
        $this->assertSame('app:reports', $defaultDefinition->identity);
        $this->assertSame('app:reports', $defaultDefinition->resourceType);
        $this->assertSame(PoolFingerprint::fromExplicit('app:reports'), $defaultDefinition->fingerprint);
        $this->assertSame(PoolOptions::fromArray([])->toArray(), $defaultDefinition->options->toArray());
        $this->assertSame($defaultDefinition->options->toArray(), $defaultPool->getOptions()->toArray());

        $this->assertInstanceOf(PoolDefinition::class, $configuredDefinition);
        $this->assertSame('app:exports', $configuredDefinition->identity);
        $this->assertSame('app:exports', $configuredDefinition->resourceType);
        $this->assertSame(PoolFingerprint::fromExplicit('app:exports'), $configuredDefinition->fingerprint);
        $this->assertSame(20, $configuredDefinition->options->maxObjects);
        $this->assertNull($configuredDefinition->options->poolIdleTimeout);
        $this->assertSame($configuredDefinition->options->toArray(), $configuredPool->getOptions()->toArray());
    }

    public function testPoolReusesTheNamedPoolAndIgnoresTheNewCallback(): void
    {
        $first = $this->manager->pool(
            'app:reports',
            static fn (): object => new stdClass,
        );
        $second = $this->manager->pool(
            'app:reports',
            static fn (): never => throw new RuntimeException('replacement factory must be ignored'),
        );
        $object = $second->borrow();
        $second->release($object);

        $this->assertSame($first, $second);
    }

    public function testPoolRejectsDifferentOptionsForTheSameName(): void
    {
        $this->manager->pool('app:reports', static fn (): object => new stdClass);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"max_objects":{"registered":10,"requested":20}');

        $this->manager->pool(
            'app:reports',
            static fn (): object => new stdClass,
            ['max_objects' => 20],
        );
    }

    public function testPoolReplacesAClosedPool(): void
    {
        $first = $this->manager->pool('app:reports', static fn (): object => new stdClass);
        $first->close();

        $replacement = $this->manager->pool('app:reports', static fn (): object => new stdClass);

        $this->assertNotSame($first, $replacement);
        $this->assertSame($replacement, $this->manager->get('app:reports'));
    }

    public function testPoolRejectsABlankName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The pool identity must be a non-empty string.');

        $this->manager->pool('', static fn (): object => new stdClass);
    }

    public function testPoolConflictsWithAnExplicitDefinitionForAnotherResourceType(): void
    {
        $this->manager->pool('app:reports', static fn (): object => new stdClass);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists for resource type [app:reports]; requested [s3]');

        $this->manager->getOrCreate(
            $this->definition(
                identity: 'app:reports',
                resourceType: 's3',
                fingerprint: PoolFingerprint::fromExplicit('app:reports'),
            ),
            static fn (): object => new stdClass,
        );
    }

    public function testGetOrCreateRegistersThePoolAndDefinition(): void
    {
        $definition = $this->definition();
        $pool = $this->manager->getOrCreate($definition, static fn (): object => new stdClass);

        $this->assertInstanceOf(ObjectPool::class, $pool);
        $this->assertTrue($this->manager->has($definition->identity));
        $this->assertSame($pool, $this->manager->get($definition->identity));
        $this->assertSame([$definition->identity => $pool], $this->manager->getPools());
        $this->assertSame($definition, $this->manager->getDefinition($definition->identity));
    }

    public function testMatchingDefinitionReusesPoolAndIgnoresNewCreateCallback(): void
    {
        $definition = $this->definition();
        $first = $this->manager->getOrCreate(
            $definition,
            static fn (): object => new stdClass,
        );
        $second = $this->manager->getOrCreate(
            $this->definition(),
            static fn (): never => throw new RuntimeException('replacement factory must be ignored'),
        );
        $object = $second->borrow();
        $second->release($object);

        $this->assertSame($first, $second);
    }

    public function testClosedRegisteredPoolIsReplacedAsAnAbsentIdentity(): void
    {
        $firstDefinition = $this->definition();
        $first = $this->manager->getOrCreate($firstDefinition, static fn (): object => new stdClass);
        $first->close();

        $replacementDefinition = $this->definition(
            resourceType: 'gcs',
            fingerprint: 'auto:replacement',
            options: ['max_objects' => 2],
        );
        $replacement = $this->manager->getOrCreate(
            $replacementDefinition,
            static fn (): object => new stdClass,
        );

        $this->assertNotSame($first, $replacement);
        $this->assertSame($replacement, $this->manager->get($replacementDefinition->identity));
        $this->assertSame($replacementDefinition, $this->manager->getDefinition($replacementDefinition->identity));
    }

    public function testResourceTypeMismatchThrows(): void
    {
        $this->manager->getOrCreate($this->definition(), static fn (): object => new stdClass);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists for resource type [s3]; requested [gcs]');

        $this->manager->getOrCreate(
            $this->definition(resourceType: 'gcs'),
            static fn (): object => new stdClass,
        );
    }

    public function testFingerprintMismatchThrows(): void
    {
        $this->manager->getOrCreate($this->definition(), static fn (): object => new stdClass);

        try {
            $this->manager->getOrCreate(
                $this->definition(fingerprint: 'auto:second'),
                static fn (): object => new stdClass,
            );
            $this->fail('Expected the construction fingerprint mismatch to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'different construction fingerprint [auto:first] (requested [auto:second])',
                $exception->getMessage(),
            );
            $this->assertStringContainsString(
                'declare a matching explicit fingerprint only when the differing input does not affect construction',
                $exception->getMessage(),
            );
        }
    }

    public function testOptionsMismatchNamesOnlyDifferingFields(): void
    {
        $this->manager->getOrCreate($this->definition(), static fn (): object => new stdClass);

        try {
            $this->manager->getOrCreate(
                $this->definition(options: ['max_objects' => 20, 'max_idle_time' => 5]),
                static fn (): object => new stdClass,
            );
            $this->fail('Expected mismatched options to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('"max_objects":{"registered":10,"requested":20}', $exception->getMessage());
            $this->assertStringContainsString('"max_idle_time":{"registered":null,"requested":5}', $exception->getMessage());
            $this->assertStringNotContainsString('wait_timeout', $exception->getMessage());
            $this->assertStringNotContainsString('pool_idle_timeout', $exception->getMessage());
        }
    }

    public function testGetThrowsForAMissingIdentity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pool [missing] does not exist.');

        $this->manager->get('missing');
    }

    public function testPurgeUnregistersBeforeClosingAndReturnsWhetherItRemoved(): void
    {
        $definition = $this->definition();
        $pool = $this->manager->getOrCreate(
            $definition,
            static fn (): object => new stdClass,
        );
        $object = $pool->borrow();
        $pool->release($object);

        $this->assertTrue($this->manager->purge($definition->identity));
        $this->assertFalse($this->manager->purge($definition->identity));
        $this->assertFalse($this->manager->has($definition->identity));
        $this->assertNull($this->manager->getDefinition($definition->identity));
        $this->assertTrue($pool->isClosed());
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testPurgeWithUnexpectedInstanceIsANoOp(): void
    {
        $definition = $this->definition();
        $pool = $this->manager->getOrCreate($definition, static fn (): object => new stdClass);
        $other = $this->manager->getOrCreate(
            $this->definition(identity: 'manager:auto:gcs:other', resourceType: 'gcs'),
            static fn (): object => new stdClass,
        );

        $this->assertFalse($this->manager->purge($definition->identity, $other));
        $this->assertSame($pool, $this->manager->get($definition->identity));
        $this->assertFalse($pool->isClosed());
    }

    public function testPurgeAllClearsDefinitionsAndClosesEveryPool(): void
    {
        $firstDefinition = $this->definition();
        $secondDefinition = $this->definition(
            identity: 'manager:auto:gcs:second',
            resourceType: 'gcs',
            fingerprint: 'auto:second',
        );
        $first = $this->manager->getOrCreate($firstDefinition, static fn (): object => new stdClass);
        $second = $this->manager->getOrCreate($secondDefinition, static fn (): object => new stdClass);

        $this->manager->purgeAll();

        $this->assertSame([], $this->manager->getPools());
        $this->assertNull($this->manager->getDefinition($firstDefinition->identity));
        $this->assertNull($this->manager->getDefinition($secondDefinition->identity));
        $this->assertTrue($first->isClosed());
        $this->assertTrue($second->isClosed());
    }

    #[DataProvider('closeFailures')]
    public function testPurgeAllAttemptsEveryDetachedPoolAndPreservesFailurePriority(
        Throwable $firstFailure,
        Throwable $secondFailure,
        Throwable $expectedFailure,
    ): void {
        $pools = [];
        $definitions = [];
        $observedRegistries = [];

        foreach ([$firstFailure, $secondFailure, null] as $index => $failure) {
            $identity = 'pool:' . $index;
            $pool = m::mock(ObjectPool::class);
            $pool->shouldReceive('close')->once()->andReturnUsing(function () use (
                $identity,
                $failure,
                &$observedRegistries,
            ): void {
                $observedRegistries[] = [$this->manager->getPools(), $this->manager->getDefinition($identity)];

                if ($failure !== null) {
                    throw $failure;
                }
            });
            $pools[$identity] = $pool;
            $definitions[$identity] = $this->definition(identity: $identity);
        }

        (new ReflectionProperty(PoolManager::class, 'pools'))->setValue($this->manager, $pools);
        (new ReflectionProperty(PoolManager::class, 'definitions'))->setValue($this->manager, $definitions);
        $actualFailure = null;

        try {
            $this->manager->purgeAll();
        } catch (Throwable $exception) {
            $actualFailure = $exception;
        }

        $this->assertSame($expectedFailure, $actualFailure);
        $this->assertSame([[[], null], [[], null], [[], null]], $observedRegistries);
        $this->assertSame([], $this->manager->getPools());
    }

    public static function closeFailures(): array
    {
        $firstFailure = new RuntimeException('first close failed');
        $secondFailure = new RuntimeException('second close failed');
        $firstCancellation = new CanceledException('first close canceled');
        $secondCancellation = new CanceledException('second close canceled');

        return [
            'first ordinary failure' => [$firstFailure, $secondFailure, $firstFailure],
            'later cancellation takes priority' => [$firstFailure, $secondCancellation, $secondCancellation],
            'first cancellation stays primary' => [$firstCancellation, $secondCancellation, $firstCancellation],
        ];
    }

    public function testPurgeAllPreservesPoolsRegisteredDuringDetachedCleanup(): void
    {
        $replacement = null;
        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('close')->once()->andReturnUsing(function () use (&$replacement): void {
            $replacement = $this->manager->pool('reports', static fn () => new stdClass);
        });
        (new ReflectionProperty(PoolManager::class, 'pools'))->setValue($this->manager, ['reports' => $pool]);

        $this->manager->purgeAll();

        $this->assertSame($replacement, $this->manager->get('reports'));
        $this->assertFalse($replacement->isClosed());
        $this->assertNotNull($this->manager->getDefinition('reports'));
    }

    public function testConcurrentMatchingRegistrationsConverge(): void
    {
        $definition = $this->definition();

        $pools = parallel(array_fill(0, 20, fn (): ObjectPool => $this->manager->getOrCreate(
            $definition,
            static fn (): object => new stdClass,
        )));

        $first = $pools[0];
        foreach ($pools as $pool) {
            $this->assertSame($first, $pool);
        }
        $this->assertCount(1, $this->manager->getPools());
    }

    private function definition(
        string $identity = 'manager:auto:s3:first',
        string $resourceType = 's3',
        string $fingerprint = 'auto:first',
        array $options = [],
    ): PoolDefinition {
        return new PoolDefinition(
            $identity,
            $resourceType,
            $fingerprint,
            PoolOptions::fromArray($options),
        );
    }
}
