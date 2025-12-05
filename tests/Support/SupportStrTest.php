<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Str;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SupportStrTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
    }

    public function testRandomStringFactoryCanBeSet()
    {
        Str::createRandomStringsUsing(fn ($length) => 'length:' . $length);

        $this->assertSame('length:7', Str::random(7));
        $this->assertSame('length:7', Str::random(7));

        Str::createRandomStringsNormally();

        $this->assertNotSame('length:7', Str::random());
    }

    public function testRandom()
    {
        $this->assertEquals(16, strlen(Str::random()));
        $randomInteger = random_int(1, 100);
        $this->assertEquals($randomInteger, strlen(Str::random($randomInteger)));
        $this->assertIsString(Str::random());
    }
}
