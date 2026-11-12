<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Reflection;

use ArrayObject;
use DateInterval;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\Tests\PHPStan\Fixtures\Reflection\MixinHost;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ObjectType;
use PHPUnit\Framework\Attributes\Test;

class ReflectionHelperTest extends PHPStanTestCase
{
    #[Test]
    public function itChecksAGenericMixinPerSpecialization(): void
    {
        $host = $this->createReflectionProvider()->getClass(MixinHost::class);

        // DateInterval declares format() and the public $y; ArrayObject declares neither.
        $dateInterval = $host->withTypes([new ObjectType(DateInterval::class)]);
        $arrayObject = $host->withTypes([new ObjectType(ArrayObject::class)]);

        $this->assertTrue(ReflectionHelper::hasMethodTag($dateInterval, 'format'));
        $this->assertTrue(ReflectionHelper::hasPropertyTag($dateInterval, 'y'));
        $this->assertFalse(ReflectionHelper::hasMethodTag($arrayObject, 'format'));
        $this->assertFalse(ReflectionHelper::hasPropertyTag($arrayObject, 'y'));
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
