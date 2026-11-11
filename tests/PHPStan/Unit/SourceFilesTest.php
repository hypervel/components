<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\Tests\PHPStan\Fixtures\SourceFiles\EnumCaseDefault;
use Hypervel\Tests\PHPStan\Fixtures\SourceFiles\SelfReferencingConstants;
use Hypervel\Tests\PHPStan\Fixtures\SourceFiles\UntypedWithoutDefault;
use Hypervel\Tests\PHPStan\Fixtures\SourceFiles\Visibility;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;

class SourceFilesTest extends PHPStanTestCase
{
    /**
     * Constants referring to each other fail only once they run, as when an analyzed casts() method reads them.
     */
    #[Test]
    public function itFindsTheFilesOfSelfReferencingConstants(): void
    {
        $reflectionProvider = $this->createReflectionProvider();
        $class = $reflectionProvider->getClass(SelfReferencingConstants::class);

        $this->assertSame([(string) $class->getFileName()], SourceFiles::forConstant($reflectionProvider, $class, 'FIRST'));
    }

    #[Test]
    public function itFindsTheFileOfAUnitEnumCaseReadByAPropertyDefault(): void
    {
        $reflectionProvider = $this->createReflectionProvider();
        $class = $reflectionProvider->getClass(EnumCaseDefault::class);

        $this->assertSame(
            [(string) $class->getFileName(), (string) $reflectionProvider->getClass(Visibility::class)->getFileName()],
            SourceFiles::forInstance($reflectionProvider, $class, null),
        );
    }

    /**
     * An untyped property without a default holds null but has no default expression.
     */
    #[Test]
    public function itReadsAnUntypedPropertyWithoutADefault(): void
    {
        $reflectionProvider = $this->createReflectionProvider();
        $class = $reflectionProvider->getClass(UntypedWithoutDefault::class);

        $this->assertSame([(string) $class->getFileName()], SourceFiles::forInstance($reflectionProvider, $class, null));
    }
}
