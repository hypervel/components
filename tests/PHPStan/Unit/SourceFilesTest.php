<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\Tests\PHPStan\Fixtures\SourceFiles\SelfReferencingConstants;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;

class SourceFilesTest extends PHPStanTestCase
{
    /**
     * Constants referring to each other fail only once they run, as when an analysed casts() method reads them.
     */
    #[Test]
    public function itFindsTheFilesOfSelfReferencingConstants(): void
    {
        $reflectionProvider = $this->createReflectionProvider();
        $class = $reflectionProvider->getClass(SelfReferencingConstants::class);

        $this->assertSame([(string) $class->getFileName()], SourceFiles::forConstant($reflectionProvider, $class, 'FIRST'));
    }
}
