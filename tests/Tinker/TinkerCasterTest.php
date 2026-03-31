<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Hypervel\Tinker\TinkerCaster;

/**
 * @internal
 * @coversNothing
 */
class TinkerCasterTest extends TestCase
{
    public function testCanCastCollection()
    {
        $result = TinkerCaster::castCollection(new Collection(['foo', 'bar']));

        $this->assertSame([['foo', 'bar']], array_values($result));
    }
}
