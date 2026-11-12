<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MethodsClassReflectionExtensionTest extends TypeInferenceTestCase
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
        yield from self::gatherAssertTypes(__DIR__ . '/data/macros.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/redirect-response.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/macros-php-81.php');
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
