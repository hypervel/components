<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use BackedEnum;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Permission\Models\Role as BaseRole;
use Hypervel\Support\Str;

class Role extends BaseRole
{
    use SoftDeletes;

    public const string HIERARCHY_TABLE = 'roles_hierarchy';

    protected string $primaryKey = 'role_test_id';

    protected array $visible = [
        'role_test_id',
        'name',
    ];

    public function getNameAttribute(): BackedEnum|string
    {
        $name = $this->attributes['name'];

        if (str_contains($name, 'casted_enum')) {
            return TestRolePermissionsEnum::from($name);
        }

        return $name;
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            static::class,
            static::HIERARCHY_TABLE,
            'child_id',
            'parent_id'
        );
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            static::class,
            static::HIERARCHY_TABLE,
            'parent_id',
            'child_id'
        );
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(static function ($model) {
            if ($model->getAttribute($model->getKeyName()) === null || $model->getAttribute($model->getKeyName()) === '') {
                $model->{$model->getKeyName()} = Str::uuid()->toString();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
