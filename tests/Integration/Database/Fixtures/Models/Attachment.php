<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;

class Attachment extends Model
{
    public bool $timestamps = false;
}
