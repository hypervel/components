<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;
use WeakReference;

class SerializableClassPolicyTest extends TestCase
{
    public function testNoConfiguredResolverIsUnrestrictedAndSkipsDeclarations(): void
    {
        $resolverRuns = 0;
        $policy = new SerializableClassPolicy;
        $policy->allowUsing(static function () use (&$resolverRuns): array {
            ++$resolverRuns;

            return [SerializablePolicyAllowedClass::class];
        });

        $result = $policy->unserialize(serialize(new SerializablePolicyConfiguredClass));

        $this->assertInstanceOf(SerializablePolicyConfiguredClass::class, $result);
        $this->assertSame(0, $resolverRuns);
    }

    public function testNullAndTrueConfiguredValuesAreUnrestrictedAndSkipDeclarations(): void
    {
        foreach ([null, true] as $configured) {
            $resolverRuns = 0;
            $policy = new SerializableClassPolicy(static fn () => $configured);
            $policy->allowUsing(static function () use (&$resolverRuns): array {
                ++$resolverRuns;

                return [SerializablePolicyAllowedClass::class];
            });

            $result = $policy->unserialize(serialize(new SerializablePolicyConfiguredClass));

            $this->assertInstanceOf(SerializablePolicyConfiguredClass::class, $result);
            $this->assertSame(0, $resolverRuns);
        }
    }

    public function testFalseWithoutDeclarationsDeniesClasses(): void
    {
        $policy = new SerializableClassPolicy(static fn (): false => false);

        $result = $policy->unserialize(serialize(new SerializablePolicyAllowedClass));

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $result);
    }

    public function testFalseWithDeclarationsAllowsContributedClasses(): void
    {
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static fn (): array => [SerializablePolicyAllowedClass::class]);

        $result = $policy->unserialize(serialize(new SerializablePolicyAllowedClass));

        $this->assertInstanceOf(SerializablePolicyAllowedClass::class, $result);
    }

    public function testConfiguredAndDeclaredClassesAreUnionedAcrossCollidingKeys(): void
    {
        $policy = new SerializableClassPolicy(static fn (): array => [
            'configured' => SerializablePolicyConfiguredClass::class,
            'duplicate' => SerializablePolicyAllowedClass::class,
        ]);
        $policy->allowUsing(static fn (): array => [
            'duplicate' => SerializablePolicyDeclaredClass::class,
            'allowed' => SerializablePolicyAllowedClass::class,
        ]);
        $policy->finalize();

        $value = [
            new SerializablePolicyConfiguredClass,
            new SerializablePolicyDeclaredClass,
            new SerializablePolicyAllowedClass,
        ];
        $result = $policy->unserialize(serialize($value));

        $this->assertInstanceOf(SerializablePolicyConfiguredClass::class, $result[0]);
        $this->assertInstanceOf(SerializablePolicyDeclaredClass::class, $result[1]);
        $this->assertInstanceOf(SerializablePolicyAllowedClass::class, $result[2]);
    }

    public function testMultipleResolversContributeClasses(): void
    {
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static fn (): array => [SerializablePolicyAllowedClass::class]);
        $policy->allowUsing(static fn (): array => [SerializablePolicyDeclaredClass::class]);

        $result = $policy->unserialize(serialize([
            new SerializablePolicyAllowedClass,
            new SerializablePolicyDeclaredClass,
        ]));

        $this->assertInstanceOf(SerializablePolicyAllowedClass::class, $result[0]);
        $this->assertInstanceOf(SerializablePolicyDeclaredClass::class, $result[1]);
    }

    public function testUnserializeRecomputesBeforeFinalizationAndFreezesAfterward(): void
    {
        $classes = [SerializablePolicyAllowedClass::class];
        $resolverRuns = 0;
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static function () use (&$classes, &$resolverRuns): array {
            ++$resolverRuns;

            return $classes;
        });

        $this->assertInstanceOf(
            SerializablePolicyAllowedClass::class,
            $policy->unserialize(serialize(new SerializablePolicyAllowedClass)),
        );

        $classes = [SerializablePolicyDeclaredClass::class];

        $this->assertInstanceOf(
            SerializablePolicyDeclaredClass::class,
            $policy->unserialize(serialize(new SerializablePolicyDeclaredClass)),
        );

        $policy->finalize();
        $classes = [SerializablePolicyConfiguredClass::class];

        $this->assertInstanceOf(
            __PHP_Incomplete_Class::class,
            $policy->unserialize(serialize(new SerializablePolicyConfiguredClass)),
        );
        $this->assertSame(3, $resolverRuns);
    }

    public function testFinalizationIsIdempotentAndDoesNotWidenThePolicy(): void
    {
        $resolverRuns = 0;
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static function () use (&$resolverRuns): array {
            ++$resolverRuns;

            return [SerializablePolicyAllowedClass::class];
        });

        $policy->finalize();
        $policy->finalize();

        $this->assertSame(1, $resolverRuns);
        $this->assertInstanceOf(
            SerializablePolicyAllowedClass::class,
            $policy->unserialize(serialize(new SerializablePolicyAllowedClass)),
        );
        $this->assertInstanceOf(
            __PHP_Incomplete_Class::class,
            $policy->unserialize(serialize(new SerializablePolicyDeclaredClass)),
        );
    }

    public function testFinalizationClearsResolverCaptures(): void
    {
        $capture = new stdClass;
        $reference = WeakReference::create($capture);
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static function () use ($capture): array {
            return [spl_object_id($capture) => SerializablePolicyAllowedClass::class];
        });

        $policy->finalize();
        unset($capture);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    public function testDeclarationAfterFinalizationThrowsActionableException(): void
    {
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->finalize();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('service provider boot()');

        $policy->allowUsing(static fn (): array => [SerializablePolicyAllowedClass::class]);
    }

    public function testResolverExceptionsPropagateUnchanged(): void
    {
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static fn (): never => throw new RuntimeException('Resolver failed.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resolver failed.');

        $policy->finalize();
    }

    public function testInvalidConfiguredValueTypeFails(): void
    {
        $policy = new SerializableClassPolicy(static fn (): string => 'invalid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.serializable_classes');

        $policy->finalize();
    }

    public function testNonArrayResolverResultFails(): void
    {
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static fn (): false => false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('resolver');

        $policy->finalize();
    }

    public function testAssociativeConfiguredAndResolverArraysAreAccepted(): void
    {
        $policy = new SerializableClassPolicy(static fn (): array => [
            'allowed' => SerializablePolicyAllowedClass::class,
        ]);
        $policy->allowUsing(static fn (): array => [
            'declared' => SerializablePolicyDeclaredClass::class,
        ]);

        $result = $policy->unserialize(serialize([
            new SerializablePolicyAllowedClass,
            new SerializablePolicyDeclaredClass,
        ]));

        $this->assertInstanceOf(SerializablePolicyAllowedClass::class, $result[0]);
        $this->assertInstanceOf(SerializablePolicyDeclaredClass::class, $result[1]);
    }

    public function testNonStringConfiguredEntryNamesItsSourceAndKey(): void
    {
        $policy = new SerializableClassPolicy(static fn (): array => [
            'invalid' => new stdClass,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.serializable_classes configuration entry [invalid]');

        $policy->finalize();
    }

    public function testNonStringResolverEntryNamesItsSourceAndKey(): void
    {
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $policy->allowUsing(static fn (): array => [
            'invalid' => new stdClass,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('serializable class resolver [0] entry [invalid]');

        $policy->finalize();
    }

    public function testUnknownClassNamesAreRetainedWithoutAutoloading(): void
    {
        $autoloadedClasses = [];
        $autoload = static function (string $class) use (&$autoloadedClasses): void {
            $autoloadedClasses[] = $class;
        };
        spl_autoload_register($autoload);

        try {
            $policy = new SerializableClassPolicy(
                static fn (): array => ['MissingSerializablePolicyClass']
            );
            $policy->finalize();
            $result = $policy->unserialize(serialize(new SerializablePolicyAllowedClass));
        } finally {
            spl_autoload_unregister($autoload);
        }

        $this->assertSame([], $autoloadedClasses);
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $result);
    }
}

class SerializablePolicyAllowedClass
{
}

class SerializablePolicyConfiguredClass
{
}

class SerializablePolicyDeclaredClass
{
}
