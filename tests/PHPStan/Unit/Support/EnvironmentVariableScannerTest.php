<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Support;

use Hypervel\PHPStan\Support\EnvironmentVariableScanner;
use Hypervel\Testing\ParallelTesting;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function file_put_contents;
use function unlink;

class EnvironmentVariableScannerTest extends PHPStanTestCase
{
    #[DataProvider('readerCallables')]
    public function testReaderCallableReadsAnyVariable(string $statement): void
    {
        $file = ParallelTesting::tempDir('PHPStanEnvironmentVariableScanner') . '.php';
        file_put_contents($file, "<?php\n\nuse Hypervel\\Support\\Env;\n\n{$statement}\n");

        try {
            $this->assertSame(
                ['names' => [], 'patterns' => ['*']],
                self::getContainer()->getByType(EnvironmentVariableScanner::class)->scan([$file], []),
            );
        } finally {
            unlink($file);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readerCallables(): array
    {
        return [
            'helper' => ['$read = env(...);'],
            'Env method' => ['$read = Env::get(...);'],
            'repository' => ['$read = Env::getRepository()->get(...);'],
        ];
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
