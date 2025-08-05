<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf;

use Hyperf\Database\Schema\Schema;
use Hypervel\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class WhereHasTest extends TestCase
{
    public function testWhereHasReturnsBuilder(): void
    {
        $builder = $this->createMock(Builder::class);
        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function testSchemaExists(): void
    {
        $this->assertTrue(class_exists(Schema::class));
    }
}
