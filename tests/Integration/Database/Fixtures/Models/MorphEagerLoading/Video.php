<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\MorphEagerLoading;

use Hypervel\Database\Eloquent\Model;

class Video extends Model
{
    public bool $timestamps = false;

    protected string $primaryKey = 'video_id';
}
