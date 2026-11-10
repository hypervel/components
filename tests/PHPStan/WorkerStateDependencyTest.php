<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use PHPUnit\Framework\Attributes\TestWith;

use function file_get_contents;
use function file_put_contents;
use function str_replace;

class WorkerStateDependencyTest extends ResultCacheTestCase
{
    protected string $fixture = 'WorkerStateDependency';

    /**
     * Whether a write's class is shared comes from the constructors and attributes of the classes
     * the container builds, which other files declare and the write's collected data does not hold.
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testChangedLifetimesRecheckUnchangedWrites(bool $reuseCollectedDataRuleErrors): void
    {
        $applicationPath = $this->copyApplication();
        $servicePath = $applicationPath . '/app/ReportService.php';
        $cachePath = $applicationPath . '/app/RequestCache.php';

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: $reuseCollectedDataRuleErrors), 0));

        file_put_contents($servicePath, str_replace('__construct()', '__construct(protected RequestCache $cache)', file_get_contents($servicePath)));

        $this->assertSame(
            ['RequestCache.php:15 hypervel.workerSharedState'],
            $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: $reuseCollectedDataRuleErrors), 1),
        );

        file_put_contents($cachePath, str_replace("\nclass RequestCache", "\n#[\\Hypervel\\Container\\Attributes\\Scoped]\nclass RequestCache", file_get_contents($cachePath)));

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: $reuseCollectedDataRuleErrors), 0));
    }
}
