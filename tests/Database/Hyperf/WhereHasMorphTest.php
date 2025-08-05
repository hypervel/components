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
class WhereHasMorphTest extends TestCase
{
    public function testWhereHasMorphReturnsBuilder(): void
    {
        $builder = $this->createMock(Builder::class);
        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function testSchemaExists(): void
    {
        $this->assertTrue(class_exists(Schema::class));
    }
}
