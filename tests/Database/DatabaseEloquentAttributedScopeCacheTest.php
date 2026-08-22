<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\TestCase;

class DatabaseEloquentAttributedScopeCacheTest extends TestCase
{
    public function testExistingScopeMetadataIsCachedWithoutCaseDuplicates(): void
    {
        $model = new AttributedScopeCacheModel;

        $this->assertTrue($model->hasNamedScope('active'));
        $this->assertSame(1, AttributedScopeCacheModel::cachedScopeMethodCount());

        $this->assertTrue($model->hasNamedScope('ACTIVE'));
        $this->assertSame(1, AttributedScopeCacheModel::cachedScopeMethodCount());
    }

    public function testCachedScopeMetadataIsConsulted(): void
    {
        AttributedScopeCacheModel::seedScopeMethodCache('ordinary', true);

        $this->assertTrue((new AttributedScopeCacheModel)->hasNamedScope('ordinary'));
    }

    public function testExistingNonScopeAndPrivateMethodResultsAreCached(): void
    {
        $model = new AttributedScopeCacheModel;

        $this->assertFalse($model->hasNamedScope('ordinary'));
        $this->assertFalse($model->hasNamedScope('privateScope'));
        $this->assertSame(2, AttributedScopeCacheModel::cachedScopeMethodCount());

        $this->assertFalse($model->hasNamedScope('ordinary'));
        $this->assertFalse($model->hasNamedScope('privateScope'));
        $this->assertSame(2, AttributedScopeCacheModel::cachedScopeMethodCount());
    }

    public function testCacheKeysAreIsolatedByModelClass(): void
    {
        $this->assertTrue((new AttributedScopeCacheModel)->hasNamedScope('active'));
        $this->assertTrue((new SecondAttributedScopeCacheModel)->hasNamedScope('active'));

        $this->assertSame(2, AttributedScopeCacheModel::cachedScopeMethodCount());
    }

    public function testMissingMethodNamesAreNotCached(): void
    {
        $model = new AttributedScopeCacheModel;

        foreach (range(1, 100) as $index) {
            $this->assertFalse($model->hasNamedScope('missingScope' . $index));
        }

        $this->assertSame(0, AttributedScopeCacheModel::cachedScopeMethodCount());
    }

    public function testLegacyScopesRespectVisibilityAndShareCaseInsensitiveCacheEntries(): void
    {
        $model = new AttributedScopeCacheModel;

        $this->assertTrue($model->hasNamedScope('visible'));
        $this->assertTrue($model->hasNamedScope('VISIBLE'));
        $this->assertFalse($model->hasNamedScope('hidden'));
        $this->assertSame(2, AttributedScopeCacheModel::cachedLegacyScopeMethodCount());
    }

    public function testInheritedLegacyScopesRespectVisibility(): void
    {
        $model = new SecondAttributedScopeCacheModel;

        $this->assertTrue($model->hasNamedScope('visible'));
        $this->assertFalse($model->hasNamedScope('hidden'));
    }

    public function testScopePrefixedMethodsAreNotTreatedAsLegacyScopes(): void
    {
        $model = new AttributedScopeCacheModel;

        $this->assertFalse($model->hasNamedScope('d'));
        $this->assertSame(1, AttributedScopeCacheModel::cachedLegacyScopeMethodCount());

        $this->assertFalse($model->hasNamedScope('d'));
        $this->assertSame(1, AttributedScopeCacheModel::cachedLegacyScopeMethodCount());
    }

    public function testModelStaticStateResetsClearTheCache(): void
    {
        $model = new AttributedScopeCacheModel;
        $this->assertTrue($model->hasNamedScope('active'));
        $this->assertTrue($model->hasNamedScope('visible'));
        $this->assertSame(1, AttributedScopeCacheModel::cachedScopeMethodCount());
        $this->assertSame(1, AttributedScopeCacheModel::cachedLegacyScopeMethodCount());

        Model::clearBootedModels();
        $this->assertSame(0, AttributedScopeCacheModel::cachedScopeMethodCount());
        $this->assertSame(0, AttributedScopeCacheModel::cachedLegacyScopeMethodCount());

        $this->assertTrue($model->hasNamedScope('active'));
        $this->assertTrue($model->hasNamedScope('visible'));
        $this->assertSame(1, AttributedScopeCacheModel::cachedScopeMethodCount());
        $this->assertSame(1, AttributedScopeCacheModel::cachedLegacyScopeMethodCount());

        Model::flushState();
        $this->assertSame(0, AttributedScopeCacheModel::cachedScopeMethodCount());
        $this->assertSame(0, AttributedScopeCacheModel::cachedLegacyScopeMethodCount());
    }
}

class AttributedScopeCacheModel extends Model
{
    #[Scope]
    protected function active(Builder $builder): void
    {
    }

    protected function ordinary(): void
    {
    }

    public static function scoped(array $attributes): string
    {
        return 'scoped';
    }

    protected function scopeVisible(): void
    {
    }

    private function scopeHidden(): void
    {
    }

    #[Scope]
    private function privateScope(Builder $builder): void
    {
    }

    public static function cachedScopeMethodCount(): int
    {
        return count(static::$scopeMethodAttributes);
    }

    public static function cachedLegacyScopeMethodCount(): int
    {
        return count(static::$legacyScopeMethods);
    }

    public static function seedScopeMethodCache(string $method, bool $isScope): void
    {
        static::$scopeMethodAttributes[static::class . "\0" . strtolower($method)] = $isScope;
    }
}

class SecondAttributedScopeCacheModel extends AttributedScopeCacheModel
{
}
