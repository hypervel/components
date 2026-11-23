<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Reflection;

use Hypervel\Tests\PHPStan\Fixtures\Managers\ExportManager;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;

class ManagersMethodsExtensionTest extends PHPStanTestCase
{
    #[Test]
    public function itForwardsDriverMethodsToManagerInstancesOnly(): void
    {
        $manager = $this->createReflectionProvider()->getClass(ExportManager::class);

        // Manager has no __callStatic(), so even the driver's static method is called on a manager instance.
        $this->assertFalse($manager->getMethod('make', new OutOfClassScope)->isStatic());
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
