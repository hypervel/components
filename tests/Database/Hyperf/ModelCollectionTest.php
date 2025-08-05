<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf;

use Hyperf\Database\Schema\Schema;
use Hypervel\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ModelCollectionTest extends TestCase
{
    public function testCollectionMakeReturnsCollection(): void
    {
        $collection = Collection::make([]);
        $this->assertInstanceOf(Collection::class, $collection);
    }

    public function testSchemaExists(): void
    {
        $this->assertTrue(class_exists(Schema::class));
    }
}
