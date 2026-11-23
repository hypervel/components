<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Closure;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PHPStan\Analyser\Analyser;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class NoUntypedConfigReadsRuleTest extends PHPStanTestCase
{
    #[DataProvider('analysisModes')]
    public function testOnlyOneRuleReportsEachUntypedRead(bool $package): void
    {
        $setMode = Closure::bind(static function (bool $value): bool {
            $previous = RuntimeInputs::$packageApplication;
            RuntimeInputs::$packageApplication = $value;

            return $previous;
        }, null, RuntimeInputs::class);

        $analyser = self::getContainer()->getByType(Analyser::class);
        $previous = $setMode($package);

        try {
            $errors = $analyser->analyse([__DIR__ . '/data/untyped-config-reads.php'], null, null, true)->getErrors();
        } finally {
            $setMode($previous);
        }

        $reads = [];

        foreach ($errors as $error) {
            if (! in_array($error->getIdentifier(), ['hypervel.untypedConfigRead', 'hypervel.typedConfigGetter', 'ignore.unmatchedIdentifier'], true)) {
                continue;
            }

            $this->assertSame('hypervel.untypedConfigRead', $error->getIdentifier());
            $this->assertSame('Without a default, the key must exist. If the key may be omitted, pass its documented fallback. If omission means null, use a nullable getter with an explicit null. If no typed getter covers the values you accept, or you need to inspect malformed input, keep the untyped config read and explain why with a local @phpstan-ignore hypervel.untypedConfigRead (reason).', $error->getTip());
            $reads[] = $error->getLine();
            if ($error->getLine() === 19) {
                $this->assertSame($package
                    ? 'Read configuration with the typed getter that matches the values this caller accepts.'
                    : "Read config key 'typed.name' with string().", $error->getMessage());
            }
        }

        $this->assertSame([19, 20, 21, 22, 23, 24, 25, 26, 27, 48], $reads);
    }

    /**
     * Supply application and package analysis modes.
     */
    public static function analysisModes(): array
    {
        return [[false], [true]];
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
