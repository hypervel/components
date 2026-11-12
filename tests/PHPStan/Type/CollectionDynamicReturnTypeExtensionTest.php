<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CollectionDynamicReturnTypeExtensionTest extends TypeInferenceTestCase
{
    #[DataProvider('dataFileAsserts')]
    public function testFileAsserts(
        string $assertType,
        string $file,
        mixed ...$args,
    ): void {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /** @return iterable<mixed> */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-filter.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-reject.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-where.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-narrowing-boundaries.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-where-not-null.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-nullsafe-chain.php');
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../src/phpstan/extension.neon'];
    }
}
