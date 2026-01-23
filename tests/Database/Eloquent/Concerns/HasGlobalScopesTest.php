<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model as HyperfModel;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Database\Eloquent\Attributes\ScopedBy;
use Hypervel\Database\Eloquent\Concerns\HasGlobalScopes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasGlobalScopesTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear global scopes between tests
        \Hyperf\Database\Model\GlobalScope::$container = [];

        parent::tearDown();
    }

    public function testResolveGlobalScopeAttributesReturnsEmptyArrayWhenNoAttributes(): void
    {
        $result = ModelWithoutScopedBy::resolveGlobalScopeAttributes();

        $this->assertSame([], $result);
    }

    public function testResolveGlobalScopeAttributesReturnsSingleScope(): void
    {
        $result = ModelWithSingleScope::resolveGlobalScopeAttributes();

        $this->assertSame([ActiveScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesReturnsMultipleScopesFromArray(): void
    {
        $result = ModelWithMultipleScopesInArray::resolveGlobalScopeAttributes();

        $this->assertSame([ActiveScope::class, TenantScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesReturnsMultipleScopesFromRepeatableAttribute(): void
    {
        $result = ModelWithRepeatableScopedBy::resolveGlobalScopeAttributes();

        $this->assertSame([ActiveScope::class, TenantScope::class], $result);
    }

    /**
     * Laravel does NOT inherit ScopedBy attributes from parent classes.
     * PHP attributes are not inherited by default, and Laravel does not
     * implement custom inheritance logic for ScopedBy.
     */
    public function testResolveGlobalScopeAttributesDoesNotInheritFromParentClass(): void
    {
        $result = ChildModelWithOwnScope::resolveGlobalScopeAttributes();

        // Only child's scope, NOT parent's - Laravel does not inherit ScopedBy
        $this->assertSame([ChildScope::class], $result);
    }

    /**
     * Laravel does NOT inherit ScopedBy attributes from parent classes.
     */
    public function testResolveGlobalScopeAttributesDoesNotInheritFromParentWhenChildHasNoAttributes(): void
    {
        $result = ChildModelWithoutOwnScope::resolveGlobalScopeAttributes();

        // Empty - child has no ScopedBy, and parent's is not inherited
        $this->assertSame([], $result);
    }

    /**
     * Laravel does NOT inherit ScopedBy attributes from parent/grandparent classes.
     */
    public function testResolveGlobalScopeAttributesDoesNotInheritFromGrandparent(): void
    {
        $result = GrandchildModelWithScope::resolveGlobalScopeAttributes();

        // Only grandchild's own scope, NOT parent's or grandparent's
        $this->assertSame([GrandchildScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesDoesNotInheritFromModelBaseClass(): void
    {
        // Models that directly extend Model should not try to resolve
        // parent attributes since Model itself has no ScopedBy attribute
        $result = ModelWithSingleScope::resolveGlobalScopeAttributes();

        $this->assertSame([ActiveScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesCollectsFromTrait(): void
    {
        $result = ModelUsingTraitWithScope::resolveGlobalScopeAttributes();

        $this->assertSame([TraitScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesCollectsMultipleScopesFromTrait(): void
    {
        $result = ModelUsingTraitWithMultipleScopes::resolveGlobalScopeAttributes();

        $this->assertSame([TraitFirstScope::class, TraitSecondScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesCollectsFromMultipleTraits(): void
    {
        $result = ModelUsingMultipleTraitsWithScopes::resolveGlobalScopeAttributes();

        // Both traits' scopes should be collected
        $this->assertSame([TraitScope::class, AnotherTraitScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesMergesTraitAndClassScopes(): void
    {
        $result = ModelWithTraitAndOwnScope::resolveGlobalScopeAttributes();

        // Class attributes come first, then trait attributes (reflection order)
        $this->assertSame([ActiveScope::class, TraitScope::class], $result);
    }

    /**
     * Laravel does NOT inherit ScopedBy from parent classes or their traits.
     */
    public function testResolveGlobalScopeAttributesDoesNotInheritParentTraitScopes(): void
    {
        $result = ChildModelWithTraitParent::resolveGlobalScopeAttributes();

        // Only child's class scope - parent's trait scope is NOT inherited
        $this->assertSame([ChildScope::class], $result);
    }

    /**
     * Laravel does NOT inherit ScopedBy from parent classes.
     * Only the child's own attributes and traits are resolved.
     */
    public function testResolveGlobalScopeAttributesOnlyResolvesOwnScopesNotParent(): void
    {
        $result = ChildModelWithAllScopeSources::resolveGlobalScopeAttributes();

        // Only child's class scope and child's trait scope
        // Parent's ParentScope is NOT inherited
        $this->assertSame([ChildScope::class, TraitScope::class], $result);
    }

    public function testAddGlobalScopesRegistersMultipleScopes(): void
    {
        ModelWithoutScopedBy::addGlobalScopes([
            ActiveScope::class,
            TenantScope::class,
        ]);

        $this->assertTrue(ModelWithoutScopedBy::hasGlobalScope(ActiveScope::class));
        $this->assertTrue(ModelWithoutScopedBy::hasGlobalScope(TenantScope::class));
    }

    public function testAddGlobalScopeSupportsClassString(): void
    {
        ModelWithoutScopedBy::addGlobalScope(ActiveScope::class);

        $this->assertTrue(ModelWithoutScopedBy::hasGlobalScope(ActiveScope::class));
        $this->assertInstanceOf(ActiveScope::class, ModelWithoutScopedBy::getGlobalScope(ActiveScope::class));
    }

    public function testPivotModelSupportsScopedByAttribute(): void
    {
        $result = PivotWithScope::resolveGlobalScopeAttributes();

        $this->assertSame([PivotScope::class], $result);
    }

    /**
     * Laravel does NOT inherit ScopedBy from parent Pivot classes.
     */
    public function testPivotModelDoesNotInheritScopesFromParent(): void
    {
        $result = ChildPivotWithScope::resolveGlobalScopeAttributes();

        // Only child's scope - parent's PivotScope is NOT inherited
        $this->assertSame([ChildPivotScope::class], $result);
    }

    public function testMorphPivotModelSupportsScopedByAttribute(): void
    {
        $result = MorphPivotWithScope::resolveGlobalScopeAttributes();

        $this->assertSame([MorphPivotScope::class], $result);
    }
}

// Test scope classes
class ActiveScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
        $builder->where('active', true);
    }
}

class TenantScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
        $builder->where('tenant_id', 1);
    }
}

class ParentScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class ChildScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class MiddleScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class GrandchildScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class TraitScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class TraitFirstScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class TraitSecondScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class AnotherTraitScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class PivotScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class ChildPivotScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

class MorphPivotScope implements Scope
{
    public function apply(Builder $builder, HyperfModel $model): void
    {
    }
}

// Test model classes
class ModelWithoutScopedBy extends Model
{
    use HasGlobalScopes;

    protected ?string $table = 'test_models';
}

#[ScopedBy(ActiveScope::class)]
class ModelWithSingleScope extends Model
{
    protected ?string $table = 'test_models';
}

#[ScopedBy([ActiveScope::class, TenantScope::class])]
class ModelWithMultipleScopesInArray extends Model
{
    protected ?string $table = 'test_models';
}

#[ScopedBy(ActiveScope::class)]
#[ScopedBy(TenantScope::class)]
class ModelWithRepeatableScopedBy extends Model
{
    protected ?string $table = 'test_models';
}

// Inheritance test models
#[ScopedBy(ParentScope::class)]
class ParentModelWithScope extends Model
{
    protected ?string $table = 'test_models';
}

#[ScopedBy(ChildScope::class)]
class ChildModelWithOwnScope extends ParentModelWithScope
{
}

class ChildModelWithoutOwnScope extends ParentModelWithScope
{
}

#[ScopedBy(MiddleScope::class)]
class MiddleModelWithScope extends ParentModelWithScope
{
}

#[ScopedBy(GrandchildScope::class)]
class GrandchildModelWithScope extends MiddleModelWithScope
{
}

// Traits with ScopedBy attributes
#[ScopedBy(TraitScope::class)]
trait TraitWithScope
{
}

#[ScopedBy([TraitFirstScope::class, TraitSecondScope::class])]
trait TraitWithMultipleScopes
{
}

#[ScopedBy(AnotherTraitScope::class)]
trait AnotherTraitWithScope
{
}

// Models using traits with scopes
class ModelUsingTraitWithScope extends Model
{
    use TraitWithScope;

    protected ?string $table = 'test_models';
}

class ModelUsingTraitWithMultipleScopes extends Model
{
    use TraitWithMultipleScopes;

    protected ?string $table = 'test_models';
}

class ModelUsingMultipleTraitsWithScopes extends Model
{
    use TraitWithScope;
    use AnotherTraitWithScope;

    protected ?string $table = 'test_models';
}

#[ScopedBy(ActiveScope::class)]
class ModelWithTraitAndOwnScope extends Model
{
    use TraitWithScope;

    protected ?string $table = 'test_models';
}

// Parent model that uses a trait with scope
class ParentModelUsingTrait extends Model
{
    use TraitWithScope;

    protected ?string $table = 'test_models';
}

#[ScopedBy(ChildScope::class)]
class ChildModelWithTraitParent extends ParentModelUsingTrait
{
}

// Child model with parent class scope, own trait, and own scope
#[ScopedBy(ChildScope::class)]
class ChildModelWithAllScopeSources extends ParentModelWithScope
{
    use TraitWithScope;
}

// Pivot test models
#[ScopedBy(PivotScope::class)]
class PivotWithScope extends Pivot
{
    protected ?string $table = 'test_pivots';
}

#[ScopedBy(ChildPivotScope::class)]
class ChildPivotWithScope extends PivotWithScope
{
}

#[ScopedBy(MorphPivotScope::class)]
class MorphPivotWithScope extends MorphPivot
{
    protected ?string $table = 'test_morph_pivots';
}
