<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Concerns;

use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Model as HyperfModel;
use Hyperf\Database\Model\Scope;
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

    public function testResolveGlobalScopeAttributesInheritsFromParentClass(): void
    {
        $result = ChildModelWithOwnScope::resolveGlobalScopeAttributes();

        // Parent's scope comes first, then child's
        $this->assertSame([ParentScope::class, ChildScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesInheritsFromParentWhenChildHasNoAttributes(): void
    {
        $result = ChildModelWithoutOwnScope::resolveGlobalScopeAttributes();

        $this->assertSame([ParentScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesInheritsFromGrandparent(): void
    {
        $result = GrandchildModelWithScope::resolveGlobalScopeAttributes();

        // Should have grandparent's, parent's, and own scope
        $this->assertSame([ParentScope::class, MiddleScope::class, GrandchildScope::class], $result);
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

        // Trait scopes come first, then class scopes
        $this->assertSame([TraitScope::class, ActiveScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesMergesParentTraitAndChildScopes(): void
    {
        $result = ChildModelWithTraitParent::resolveGlobalScopeAttributes();

        // Parent's trait scope -> child's class scope
        $this->assertSame([TraitScope::class, ChildScope::class], $result);
    }

    public function testResolveGlobalScopeAttributesCorrectOrderWithParentTraitsAndChild(): void
    {
        $result = ChildModelWithAllScopeSources::resolveGlobalScopeAttributes();

        // Order: parent class -> parent trait -> child trait -> child class
        // ParentModelWithScope has ParentScope
        // ChildModelWithAllScopeSources uses TraitWithScope (TraitScope) and has ChildScope
        $this->assertSame([ParentScope::class, TraitScope::class, ChildScope::class], $result);
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

    public function testPivotModelInheritsScopesFromParent(): void
    {
        $result = ChildPivotWithScope::resolveGlobalScopeAttributes();

        // Parent's scope comes first, then child's
        $this->assertSame([PivotScope::class, ChildPivotScope::class], $result);
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
