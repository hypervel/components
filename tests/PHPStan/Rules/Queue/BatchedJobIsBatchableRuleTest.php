<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\PHPStan\Rules\Queue\BatchedJobIsBatchableRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<BatchedJobIsBatchableRule> */
class BatchedJobIsBatchableRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BatchedJobIsBatchableRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/bus-batch.php'], [
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                15,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                22,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                41,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\SyncReport is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                53,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                68,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                69,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                70,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                72,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                77,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                87,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                88,
            ],
            [
                "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\RegularJob is batched but does not use Batchable.\n    💡 Use the Hypervel\\Bus\\Batchable trait.",
                98,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
