<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use Generator;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ModelCastsParsingTest extends TypeInferenceTestCase
{
    #[DataProvider('dataFileAsserts')]
    public function testFileAsserts(
        string $assertType,
        string $file,
        mixed ...$args,
    ): void {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    public static function dataFileAsserts(): Generator
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-casts-parsing.php');
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        // Upstream's config-with-migrations-and-parsing-casts.neon matches the test defaults.
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
