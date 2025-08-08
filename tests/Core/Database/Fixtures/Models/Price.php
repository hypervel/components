<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Fixtures\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Core\Database\Fixtures\Factories\PriceFactory;

class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory;

    protected ?string $table = 'prices';

    protected static string $factory = PriceFactory::class;
}
