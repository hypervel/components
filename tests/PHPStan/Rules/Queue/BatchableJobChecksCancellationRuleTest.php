<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\PHPStan\Rules\Queue\BatchableJobChecksCancellationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<BatchableJobChecksCancellationRule> */
class BatchableJobChecksCancellationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BatchableJobChecksCancellationRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/batchable-jobs.php'], [
            [
                "No batch cancellation check was found in batchable job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\BatchableJobWithoutCancellationCheck.\n    💡 Check \$this->batch()?->cancelled() or \$this->batching(), or return the SkipIfBatchCancelled middleware from middleware().",
                74,
            ],
            [
                "No batch cancellation check was found in batchable job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\ConcreteBatchableJobFromAbstractBase.\n    💡 Check \$this->batch()?->cancelled() or \$this->batching(), or return the SkipIfBatchCancelled middleware from middleware().",
                84,
            ],
            [
                "No batch cancellation check was found in batchable job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobOverridingCancellationGuard.\n    💡 Check \$this->batch()?->cancelled() or \$this->batching(), or return the SkipIfBatchCancelled middleware from middleware().",
                125,
            ],
            [
                "No batch cancellation check was found in batchable job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobOverridingCancellationMiddleware.\n    💡 Check \$this->batch()?->cancelled() or \$this->batching(), or return the SkipIfBatchCancelled middleware from middleware().",
                132,
            ],
            [
                "No batch cancellation check was found in batchable job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\JobCheckingAnotherCancellation.\n    💡 Check \$this->batch()?->cancelled() or \$this->batching(), or return the SkipIfBatchCancelled middleware from middleware().",
                157,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
