<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Models;

use Hypervel\Database\Eloquent\Model;

class TransformsToResourceTestModelInModelsNamespace extends Model
{
    protected ?string $table = 'test_models';
}
