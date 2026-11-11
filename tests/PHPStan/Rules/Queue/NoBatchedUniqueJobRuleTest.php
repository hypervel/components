<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\PHPStan\Rules\Queue\NoBatchedUniqueJobRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoBatchedUniqueJobRule> */
class NoBatchedUniqueJobRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoBatchedUniqueJobRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/batched-unique-jobs.php'], [
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                12,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForMethod is dispatched via bulk().\n    💡 Dispatch unique jobs individually.",
                17,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via bulk().\n    💡 Dispatch unique jobs individually.",
                21,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                28,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                44,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                47,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForMethod is dispatched via add().\n    💡 Dispatch unique jobs individually.",
                51,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                59,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via bulk().\n    💡 Dispatch unique jobs individually.",
                61,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                68,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForMethod|Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                75,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForMethod|Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                76,
            ],
            [
                "Unique job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForMethod|Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\UniqueJobWithUniqueForProperty is dispatched via batch().\n    💡 Dispatch unique jobs individually.",
                77,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
