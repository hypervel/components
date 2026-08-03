<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\ObjectPool\Contracts\ObjectPool;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

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
        $this->manager->flush();
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
            ['max_objects' => 20, 'idle_ttl' => null],
        );

        $defaultDefinition = $this->manager->definition('app:reports');
        $configuredDefinition = $this->manager->definition('app:exports');

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
        $this->assertNull($configuredDefinition->options->idleTtl);
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
        $object = $second->get();
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
        $this->assertSame([$definition->identity => $pool], $this->manager->pools());
        $this->assertSame($definition, $this->manager->definition($definition->identity));
    }

    public function testMatchingDefinitionReusesPoolAndIgnoresNewConstructionResolver(): void
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
        $object = $second->get();
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
        $this->assertSame($replacementDefinition, $this->manager->definition($replacementDefinition->identity));
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different construction fingerprint [auto:first] (requested [auto:second])');

        $this->manager->getOrCreate(
            $this->definition(fingerprint: 'auto:second'),
            static fn (): object => new stdClass,
        );
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
            $this->assertStringContainsString('"max_idle_time":{"registered":0,"requested":5}', $exception->getMessage());
            $this->assertStringNotContainsString('wait_timeout', $exception->getMessage());
            $this->assertStringNotContainsString('idle_ttl', $exception->getMessage());
        }
    }

    public function testGetThrowsForAMissingIdentity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pool [missing] does not exist.');

        $this->manager->get('missing');
    }

    public function testRemoveUnregistersBeforeClosingAndReturnsWhetherItRemoved(): void
    {
        $definition = $this->definition();
        $pool = $this->manager->getOrCreate(
            $definition,
            static fn (): object => new stdClass,
        );
        $object = $pool->get();
        $pool->release($object);

        $this->assertTrue($this->manager->remove($definition->identity));
        $this->assertFalse($this->manager->remove($definition->identity));
        $this->assertFalse($this->manager->has($definition->identity));
        $this->assertNull($this->manager->definition($definition->identity));
        $this->assertTrue($pool->isClosed());
        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }

    public function testRemoveWithUnexpectedInstanceIsANoOp(): void
    {
        $definition = $this->definition();
        $pool = $this->manager->getOrCreate($definition, static fn (): object => new stdClass);
        $other = $this->manager->getOrCreate(
            $this->definition(identity: 'manager:auto:gcs:other', resourceType: 'gcs'),
            static fn (): object => new stdClass,
        );

        $this->assertFalse($this->manager->remove($definition->identity, $other));
        $this->assertSame($pool, $this->manager->get($definition->identity));
        $this->assertFalse($pool->isClosed());
    }

    public function testFlushClearsDefinitionsAndClosesEveryPool(): void
    {
        $firstDefinition = $this->definition();
        $secondDefinition = $this->definition(
            identity: 'manager:auto:gcs:second',
            resourceType: 'gcs',
            fingerprint: 'auto:second',
        );
        $first = $this->manager->getOrCreate($firstDefinition, static fn (): object => new stdClass);
        $second = $this->manager->getOrCreate($secondDefinition, static fn (): object => new stdClass);

        $this->manager->flush();

        $this->assertSame([], $this->manager->pools());
        $this->assertNull($this->manager->definition($firstDefinition->identity));
        $this->assertNull($this->manager->definition($secondDefinition->identity));
        $this->assertTrue($first->isClosed());
        $this->assertTrue($second->isClosed());
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
        $this->assertCount(1, $this->manager->pools());
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
