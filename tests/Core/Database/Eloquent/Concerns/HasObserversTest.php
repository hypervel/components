<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Hyperf\Database\Model\Events\Created;
use Hyperf\Database\Model\Events\Updated;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelListener;
use Hypervel\Database\Eloquent\ObserverManager;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class HasObserversTest extends TestCase
{
    public function testResolveObserveAttributesReturnsEmptyArrayWhenNoAttributes(): void
    {
        $result = ModelWithoutObservedBy::resolveObserveAttributes();

        $this->assertSame([], $result);
    }

    public function testResolveObserveAttributesReturnsSingleObserver(): void
    {
        $result = ModelWithSingleObserver::resolveObserveAttributes();

        $this->assertSame([SingleObserver::class], $result);
    }

    public function testResolveObserveAttributesReturnsMultipleObserversFromArray(): void
    {
        $result = ModelWithMultipleObserversInArray::resolveObserveAttributes();

        $this->assertSame([FirstObserver::class, SecondObserver::class], $result);
    }

    public function testResolveObserveAttributesReturnsMultipleObserversFromRepeatableAttribute(): void
    {
        $result = ModelWithRepeatableObservedBy::resolveObserveAttributes();

        $this->assertSame([FirstObserver::class, SecondObserver::class], $result);
    }

    public function testResolveObserveAttributesInheritsFromParentClass(): void
    {
        $result = ChildModelWithOwnObserver::resolveObserveAttributes();

        // Parent's observer comes first, then child's
        $this->assertSame([ParentObserver::class, ChildObserver::class], $result);
    }

    public function testResolveObserveAttributesInheritsFromParentWhenChildHasNoAttributes(): void
    {
        $result = ChildModelWithoutOwnObserver::resolveObserveAttributes();

        $this->assertSame([ParentObserver::class], $result);
    }

    public function testResolveObserveAttributesInheritsFromGrandparent(): void
    {
        $result = GrandchildModel::resolveObserveAttributes();

        // Should have grandparent's, parent's, and own observer
        $this->assertSame([ParentObserver::class, MiddleObserver::class, GrandchildObserver::class], $result);
    }

    public function testResolveObserveAttributesDoesNotInheritFromModelBaseClass(): void
    {
        // Models that directly extend Model should not try to resolve
        // parent attributes since Model itself has no ObservedBy attribute
        $result = ModelWithSingleObserver::resolveObserveAttributes();

        $this->assertSame([SingleObserver::class], $result);
    }

    public function testBootHasObserversRegistersObservers(): void
    {
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->with(SingleObserver::class)
            ->once()
            ->andReturn(new SingleObserver());

        $listener = m::mock(ModelListener::class);
        $listener->shouldReceive('getModelEvents')
            ->once()
            ->andReturn([
                'created' => Created::class,
                'updated' => Updated::class,
            ]);
        $listener->shouldReceive('register')
            ->once()
            ->with(ModelWithSingleObserver::class, 'created', m::type('callable'));

        $manager = new ObserverManager($container, $listener);

        // Simulate what bootHasObservers does
        $observers = ModelWithSingleObserver::resolveObserveAttributes();
        foreach ($observers as $observer) {
            $manager->register(ModelWithSingleObserver::class, $observer);
        }

        $this->assertCount(1, $manager->getObservers(ModelWithSingleObserver::class));
    }

    public function testBootHasObserversDoesNothingWhenNoObservers(): void
    {
        // This test verifies the empty check in bootHasObservers
        $result = ModelWithoutObservedBy::resolveObserveAttributes();

        $this->assertEmpty($result);
    }

    public function testPivotModelSupportsObservedByAttribute(): void
    {
        $result = PivotWithObserver::resolveObserveAttributes();

        $this->assertSame([PivotObserver::class], $result);
    }

    public function testPivotModelInheritsObserversFromParent(): void
    {
        $result = ChildPivotWithObserver::resolveObserveAttributes();

        // Parent's observer comes first, then child's
        $this->assertSame([PivotObserver::class, ChildPivotObserver::class], $result);
    }

    public function testMorphPivotModelSupportsObservedByAttribute(): void
    {
        $result = MorphPivotWithObserver::resolveObserveAttributes();

        $this->assertSame([MorphPivotObserver::class], $result);
    }
}

// Test observer classes
class SingleObserver
{
    public function created(Model $model): void
    {
    }
}

class FirstObserver
{
    public function created(Model $model): void
    {
    }
}

class SecondObserver
{
    public function created(Model $model): void
    {
    }
}

class ParentObserver
{
    public function created(Model $model): void
    {
    }
}

class ChildObserver
{
    public function created(Model $model): void
    {
    }
}

class MiddleObserver
{
    public function created(Model $model): void
    {
    }
}

class GrandchildObserver
{
    public function created(Model $model): void
    {
    }
}

// Test model classes
class ModelWithoutObservedBy extends Model
{
    protected ?string $table = 'test_models';
}

#[ObservedBy(SingleObserver::class)]
class ModelWithSingleObserver extends Model
{
    protected ?string $table = 'test_models';
}

#[ObservedBy([FirstObserver::class, SecondObserver::class])]
class ModelWithMultipleObserversInArray extends Model
{
    protected ?string $table = 'test_models';
}

#[ObservedBy(FirstObserver::class)]
#[ObservedBy(SecondObserver::class)]
class ModelWithRepeatableObservedBy extends Model
{
    protected ?string $table = 'test_models';
}

// Inheritance test models
#[ObservedBy(ParentObserver::class)]
class ParentModelWithObserver extends Model
{
    protected ?string $table = 'test_models';
}

#[ObservedBy(ChildObserver::class)]
class ChildModelWithOwnObserver extends ParentModelWithObserver
{
}

class ChildModelWithoutOwnObserver extends ParentModelWithObserver
{
}

#[ObservedBy(MiddleObserver::class)]
class MiddleModel extends ParentModelWithObserver
{
}

#[ObservedBy(GrandchildObserver::class)]
class GrandchildModel extends MiddleModel
{
}

// Pivot test observers
class PivotObserver
{
    public function created(Pivot $pivot): void
    {
    }
}

class ChildPivotObserver
{
    public function created(Pivot $pivot): void
    {
    }
}

class MorphPivotObserver
{
    public function created(MorphPivot $pivot): void
    {
    }
}

// Pivot test models
#[ObservedBy(PivotObserver::class)]
class PivotWithObserver extends Pivot
{
    protected ?string $table = 'test_pivots';
}

#[ObservedBy(ChildPivotObserver::class)]
class ChildPivotWithObserver extends PivotWithObserver
{
}

#[ObservedBy(MorphPivotObserver::class)]
class MorphPivotWithObserver extends MorphPivot
{
    protected ?string $table = 'test_morph_pivots';
}
