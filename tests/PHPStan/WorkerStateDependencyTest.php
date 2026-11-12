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

    /**
     * Booted registrations decide lifetimes, which no analyzed file declares.
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testChangedRegistrationsRecheckUnchangedWrites(bool $reuseCollectedDataRuleErrors): void
    {
        $applicationPath = $this->copyApplication();

        $this->edit($applicationPath . '/app/ReportService.php', '__construct()', '__construct(protected RequestCache $cache)');

        $this->assertSame(
            ['RequestCache.php:15 hypervel.workerSharedState'],
            $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: $reuseCollectedDataRuleErrors), 1),
        );

        $this->edit($applicationPath . '/bootstrap/app.php', '->create()', '->withScopedSingletons([WorkerStateDependency\RequestCache::class])->create()');

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: $reuseCollectedDataRuleErrors), 0));
    }

    /**
     * A facade's root comes from its accessor's body, which exported nodes do not hold.
     */
    public function testChangedFacadeAccessorsRecheckUnchangedCalls(): void
    {
        $applicationPath = $this->copyApplication();

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));

        $this->edit($applicationPath . '/app/ReportFacade.php', 'return SupportedReporter::class;', 'return UnsupportedReporter::class;');

        $this->assertSame(['Reporting.php:11 hypervel.unsupportedCall'], $this->diagnostics($this->analyse($applicationPath), 1));
    }

    /**
     * Whether a call passes a parameter's default depends on the declared default, which exported
     * nodes record only as present, and on the constants it reads, which they do not hold at all.
     */
    public function testChangedDefaultsRecheckUnchangedCalls(): void
    {
        $applicationPath = $this->copyApplication();

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: true), 0));

        $this->edit($applicationPath . '/app/Defaults.php', 'MAP = []', "MAP = ['default']");

        $this->assertSame(['RebuildReport.php:13 hypervel.restrictedCall'], $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: true), 1));

        $this->edit($applicationPath . '/app/Registry.php', 'array $map = Defaults::MAP', 'array $map = []');

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, reuseCollectedDataRuleErrors: true), 0));
    }
}
