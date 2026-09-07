<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\Lazy\ClosureLazy;
use Hypervel\Data\Support\Lazy\ConditionalLazy;
use Hypervel\Data\Support\Lazy\DefaultLazy;
use Hypervel\Data\Support\Lazy\RelationalLazy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Inertia\DeferProp;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Testbench\TestCase;

class LazyTest extends TestCase
{
    public function testItCreatesAndResolvesDefaultLazyValues(): void
    {
        $lazy = Lazy::create(fn () => 'value');

        $this->assertInstanceOf(DefaultLazy::class, $lazy);
        $this->assertSame('value', $lazy->resolve());
        $this->assertFalse($lazy->isDefaultIncluded());
        $this->assertSame($lazy, $lazy->defaultIncluded());
        $this->assertTrue($lazy->isDefaultIncluded());
        $this->assertTrue($lazy->resolvesToData());
    }

    public function testItCreatesConditionalLazyValues(): void
    {
        $included = Lazy::when(fn () => true, fn () => 'included');
        $excluded = Lazy::when(fn () => false, fn () => 'excluded');

        $this->assertInstanceOf(ConditionalLazy::class, $included);
        $this->assertTrue($included->shouldBeIncluded());
        $this->assertSame('included', $included->resolve());
        $this->assertFalse($excluded->shouldBeIncluded());
    }

    public function testItExposesLazyClosuresWithoutInvokingThem(): void
    {
        $calls = 0;
        $lazy = Lazy::closure(function () use (&$calls): string {
            ++$calls;

            return 'value';
        });

        $resolved = $lazy->resolve();

        $this->assertInstanceOf(ClosureLazy::class, $lazy);
        $this->assertFalse($lazy->resolvesToData());
        $this->assertInstanceOf(Closure::class, $resolved);
        $this->assertSame(0, $calls);
        $this->assertSame('value', $resolved());
        $this->assertSame(1, $calls);
    }

    public function testItCreatesInertiaLazyAndDeferredProperties(): void
    {
        $lazy = Lazy::inertia(static fn (): string => 'lazy');
        $deferred = Lazy::inertiaDeferred('deferred', 'analytics', true);

        $this->assertTrue($lazy->shouldBeIncluded());
        $this->assertFalse($lazy->resolvesToData());
        $this->assertInstanceOf(OptionalProp::class, $lazy->resolve());
        $this->assertSame('lazy', ($lazy->resolve())());

        $prop = $deferred->resolve();

        $this->assertTrue($deferred->shouldBeIncluded());
        $this->assertFalse($deferred->resolvesToData());
        $this->assertSame('analytics', $prop->group());
        $this->assertTrue($prop->shouldRescue());
        $this->assertSame('deferred', $prop());
    }

    public function testItPreservesExistingInertiaDeferredProperties(): void
    {
        $prop = (new DeferProp(static fn (): string => 'value', 'original', true))
            ->merge()
            ->once(as: 'users');

        $resolved = Lazy::inertiaDeferred($prop, 'ignored')->resolve();

        $this->assertSame($prop, $resolved);
        $this->assertSame('original', $resolved->group());
        $this->assertTrue($resolved->shouldRescue());
        $this->assertTrue($resolved->shouldMerge());
        $this->assertTrue($resolved->shouldResolveOnce());
        $this->assertSame('users', $resolved->getKey());
    }

    public function testItIncludesRelationshipValuesOnlyWhenTheRelationIsLoaded(): void
    {
        $model = new LazyTestModel;
        $lazy = Lazy::whenLoaded('related', $model, fn () => $model->related);

        $this->assertInstanceOf(RelationalLazy::class, $lazy);
        $this->assertFalse($lazy->shouldBeIncluded());

        $related = new LazyTestModel;
        $model->setRelation('related', $related);

        $this->assertTrue($lazy->shouldBeIncluded());
        $this->assertSame($related, $lazy->resolve());
    }

    public function testItReturnsNullForALoadedNullRelationship(): void
    {
        $model = new LazyTestModel;
        $model->setRelation('related', null);

        $lazy = Lazy::whenLoaded('related', $model, fn () => 'unreachable');

        $this->assertTrue($lazy->shouldBeIncluded());
        $this->assertNull($lazy->resolve());
    }

    public function testItForwardsPropertyAndMethodAccessToTheResolvedValue(): void
    {
        $target = new LazyTestTarget('Taylor');
        $lazy = Lazy::create(fn () => $target);

        $this->assertSame('Taylor', $lazy->name);
        $this->assertSame('Hello Taylor', $lazy->greet('Hello'));
    }

    public function testRegisteredMacrosTakePriorityOverResolvedMethods(): void
    {
        Lazy::macro('greet', fn (string $greeting): string => "{$greeting} macro");

        try {
            $lazy = Lazy::create(fn () => new LazyTestTarget('Taylor'));

            $this->assertSame('Hello macro', $lazy->greet('Hello'));
        } finally {
            Lazy::flushMacros();
        }
    }

    public function testSerializableLazyValuesRetainTheirBehavior(): void
    {
        // Serializable Closure cannot distinguish identical closure signatures on one source line.
        $condition = fn () => true;
        $value = fn () => 'value';
        $lazy = Lazy::when($condition, $value)->defaultIncluded();

        $restored = unserialize(serialize($lazy));

        $this->assertInstanceOf(ConditionalLazy::class, $restored);
        $this->assertTrue($restored->shouldBeIncluded());
        $this->assertTrue($restored->isDefaultIncluded());
        $this->assertSame('value', $restored->resolve());
    }

    public function testSerializableInertiaLazyValuesRetainTheirBehavior(): void
    {
        $lazyValue = fn () => 'lazy';
        $deferredValue = fn () => 'deferred';
        $lazy = Lazy::inertia($lazyValue)->defaultIncluded();
        $deferred = Lazy::inertiaDeferred($deferredValue, 'analytics', true)->defaultIncluded();

        $restoredLazy = unserialize(serialize($lazy));
        $restoredDeferred = unserialize(serialize($deferred));
        $deferredProp = $restoredDeferred->resolve();

        $this->assertTrue($restoredLazy->isDefaultIncluded());
        $this->assertSame('lazy', ($restoredLazy->resolve())());
        $this->assertTrue($restoredDeferred->isDefaultIncluded());
        $this->assertSame('analytics', $deferredProp->group());
        $this->assertTrue($deferredProp->shouldRescue());
        $this->assertSame('deferred', $deferredProp());
    }
}

class LazyTestModel extends Model
{
}

class LazyTestTarget
{
    public function __construct(
        public string $name,
    ) {
    }

    public function greet(string $greeting): string
    {
        return "{$greeting} {$this->name}";
    }
}
