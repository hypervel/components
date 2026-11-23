<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

trait CreatesParts
{
    /**
     * Create the parts table.
     */
    protected function createParts(): void
    {
        Schema::create('parts', fn (Blueprint $table) => $table->string('name'));
    }
}
