<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasLocalScopesTest extends TestCase
{
    public function testHasNamedScopeReturnsTrueForTraditionalScopePrefix(): void
    {
        $model = new ModelWithTraditionalScope();

        $this->assertTrue($model->hasNamedScope('active'));
    }

    public function testHasNamedScopeReturnsTrueForScopeAttribute(): void
    {
        $model = new ModelWithScopeAttribute();

        $this->assertTrue($model->hasNamedScope('verified'));
    }

    public function testHasNamedScopeReturnsFalseForNonExistentScope(): void
    {
        $model = new ModelWithTraditionalScope();

        $this->assertFalse($model->hasNamedScope('nonExistent'));
    }

    public function testHasNamedScopeReturnsFalseForRegularMethodWithoutAttribute(): void
    {
        $model = new ModelWithRegularMethod();

        $this->assertFalse($model->hasNamedScope('regularMethod'));
    }

    public function testCallNamedScopeCallsTraditionalScopeMethod(): void
    {
        $model = new ModelWithTraditionalScope();
        $builder = $this->createMock(Builder::class);

        $result = $model->callNamedScope('active', [$builder]);

        $this->assertSame($builder, $result);
    }

    public function testCallNamedScopeCallsScopeAttributeMethod(): void
    {
        $model = new ModelWithScopeAttribute();
        $builder = $this->createMock(Builder::class);

        $result = $model->callNamedScope('verified', [$builder]);

        $this->assertSame($builder, $result);
    }

    public function testCallNamedScopePassesParameters(): void
    {
        $model = new ModelWithParameterizedScope();
        $builder = $this->createMock(Builder::class);

        $result = $model->callNamedScope('ofType', [$builder, 'premium']);

        $this->assertSame('premium', $result);
    }

    public function testIsScopeMethodWithAttributeReturnsTrueForAttributedMethod(): void
    {
        $result = ModelWithScopeAttribute::isScopeMethodWithAttributePublic('verified');

        $this->assertTrue($result);
    }

    public function testIsScopeMethodWithAttributeReturnsFalseForTraditionalScope(): void
    {
        $result = ModelWithTraditionalScope::isScopeMethodWithAttributePublic('scopeActive');

        $this->assertFalse($result);
    }

    public function testIsScopeMethodWithAttributeReturnsFalseForNonExistentMethod(): void
    {
        $result = ModelWithScopeAttribute::isScopeMethodWithAttributePublic('nonExistent');

        $this->assertFalse($result);
    }

    public function testIsScopeMethodWithAttributeReturnsFalseForMethodWithoutAttribute(): void
    {
        $result = ModelWithRegularMethod::isScopeMethodWithAttributePublic('regularMethod');

        $this->assertFalse($result);
    }

    public function testModelHasBothTraditionalAndAttributeScopes(): void
    {
        $model = new ModelWithBothScopeTypes();

        $this->assertTrue($model->hasNamedScope('active'));
        $this->assertTrue($model->hasNamedScope('verified'));
    }

    public function testInheritedScopeAttributeIsRecognized(): void
    {
        $model = new ChildModelWithInheritedScope();

        $this->assertTrue($model->hasNamedScope('parentScope'));
    }

    public function testChildCanOverrideScopeFromParent(): void
    {
        $model = new ChildModelWithOverriddenScope();
        $builder = $this->createMock(Builder::class);

        // Should call the child's version which returns 'child'
        $result = $model->callNamedScope('sharedScope', [$builder]);

        $this->assertSame('child', $result);
    }
}

// Test models
class ModelWithTraditionalScope extends Model
{
    protected ?string $table = 'test_models';

    public function scopeActive(Builder $builder): Builder
    {
        return $builder;
    }

    public static function isScopeMethodWithAttributePublic(string $method): bool
    {
        return static::isScopeMethodWithAttribute($method);
    }
}

class ModelWithScopeAttribute extends Model
{
    protected ?string $table = 'test_models';

    #[Scope]
    protected function verified(Builder $builder): Builder
    {
        return $builder;
    }

    public static function isScopeMethodWithAttributePublic(string $method): bool
    {
        return static::isScopeMethodWithAttribute($method);
    }
}

class ModelWithParameterizedScope extends Model
{
    protected ?string $table = 'test_models';

    #[Scope]
    protected function ofType(Builder $builder, string $type): string
    {
        return $type;
    }
}

class ModelWithRegularMethod extends Model
{
    protected ?string $table = 'test_models';

    public function regularMethod(): string
    {
        return 'regular';
    }

    public static function isScopeMethodWithAttributePublic(string $method): bool
    {
        return static::isScopeMethodWithAttribute($method);
    }
}

class ModelWithBothScopeTypes extends Model
{
    protected ?string $table = 'test_models';

    public function scopeActive(Builder $builder): Builder
    {
        return $builder;
    }

    #[Scope]
    protected function verified(Builder $builder): Builder
    {
        return $builder;
    }
}

class ParentModelWithScopeAttribute extends Model
{
    protected ?string $table = 'test_models';

    #[Scope]
    protected function parentScope(Builder $builder): Builder
    {
        return $builder;
    }

    #[Scope]
    protected function sharedScope(Builder $builder): string
    {
        return 'parent';
    }
}

class ChildModelWithInheritedScope extends ParentModelWithScopeAttribute
{
}

class ChildModelWithOverriddenScope extends ParentModelWithScopeAttribute
{
    #[Scope]
    protected function sharedScope(Builder $builder): string
    {
        return 'child';
    }
}
