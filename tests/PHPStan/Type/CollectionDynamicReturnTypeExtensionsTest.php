<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CollectionDynamicReturnTypeExtensionsTest extends TypeInferenceTestCase
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
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-helper.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-make-static.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-stubs.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-key-types.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-generic-static-methods.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-collection-map.php');
        // REMOVED: collection-generic-static-methods-l11.php; Laravel 11's Eloquent partition() returned static, and Hypervel follows the Laravel 12 base collection.
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-generic-static-methods-l12.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-stubs-l12-20.php');
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
