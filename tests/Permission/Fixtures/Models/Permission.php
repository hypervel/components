<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Permission\Models\Permission as BasePermission;
use Hypervel\Support\Str;

class Permission extends BasePermission
{
    use SoftDeletes;

    protected string $primaryKey = 'permission_test_id';

    protected array $visible = [
        'permission_test_id',
        'name',
    ];

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
