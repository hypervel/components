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
use Hypervel\Tests\TestCase;

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
        $this->assertInstanceOf(Closure::class, $resolved);
        $this->assertSame(0, $calls);
        $this->assertSame('value', $resolved());
        $this->assertSame(1, $calls);
    }

    public function testItIncludesRelationshipValuesOnlyWhenTheRelationIsLoaded(): void
    {
        $model = new LazyTestModel();
        $lazy = Lazy::whenLoaded('related', $model, fn () => $model->related);

        $this->assertInstanceOf(RelationalLazy::class, $lazy);
        $this->assertFalse($lazy->shouldBeIncluded());

        $related = new LazyTestModel();
        $model->setRelation('related', $related);

        $this->assertTrue($lazy->shouldBeIncluded());
        $this->assertSame($related, $lazy->resolve());
    }

    public function testItReturnsNullForALoadedNullRelationship(): void
    {
        $model = new LazyTestModel();
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
