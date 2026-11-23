<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use PHPUnit\Framework\Attributes\Large;

use function file_get_contents;
use function file_put_contents;
use function str_replace;

#[Large]
class QueueJobDependencyTest extends ResultCacheTestCase
{
    protected string $fixture = 'QueueJobDependency';

    /**
     * A queued job's dispatch settings come from its constructor body, its property defaults and the
     * constants its attributes read, none of which are part of the declarations PHPStan compares.
     */
    public function testChangedJobSettingsReanalyzeTransactionDispatches(): void
    {
        $applicationPath = $this->copyApplication();
        $jobsPath = $applicationPath . '/app/Jobs';

        // The application's default database connection is known, unlike the analyzer's Testbench
        // application's, so Usage.php:30 shows that usingConnection() replaces it.
        $this->assertSame(
            [
                'ImportJob.php:7 hypervel.batchableJobChecksCancellation',
                'Usage.php:14 hypervel.dispatchInTransactionAfterCommit',
                'Usage.php:15 hypervel.dispatchInTransactionAfterCommit',
                'Usage.php:16 hypervel.dispatchInTransactionAfterCommit',
                'Usage.php:24 hypervel.dispatchInTransactionAfterCommit',
            ],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );

        file_put_contents($jobsPath . '/ConstructedJob.php', str_replace("{\n    }", "{\n        \$this->afterCommit();\n    }", file_get_contents($jobsPath . '/ConstructedJob.php')));

        $this->assertSame(
            [
                'ImportJob.php:7 hypervel.batchableJobChecksCancellation',
                'Usage.php:14 hypervel.dispatchInTransactionAfterCommit',
                'Usage.php:16 hypervel.dispatchInTransactionAfterCommit',
                'Usage.php:24 hypervel.dispatchInTransactionAfterCommit',
            ],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );

        file_put_contents($jobsPath . '/BaseJob.php', str_replace('$afterCommit = null', '$afterCommit = true', file_get_contents($jobsPath . '/BaseJob.php')));

        $this->assertSame(
            [
                'ImportJob.php:7 hypervel.batchableJobChecksCancellation',
                'Usage.php:14 hypervel.dispatchInTransactionAfterCommit',
                'Usage.php:24 hypervel.dispatchInTransactionAfterCommit',
            ],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );

        file_put_contents($jobsPath . '/Connections.php', str_replace("'before-commit'", "'sync'", file_get_contents($jobsPath . '/Connections.php')));

        $this->assertSame(
            ['ImportJob.php:7 hypervel.batchableJobChecksCancellation', 'Usage.php:24 hypervel.dispatchInTransactionAfterCommit'],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );
    }

    /**
     * A batchable job can inherit the method that checks for cancellation, and a method body is not
     * part of the declarations PHPStan compares.
     */
    public function testChangedInheritedHandleReanalyzesBatchableJob(): void
    {
        $applicationPath = $this->copyApplication();
        $batchJobPath = $applicationPath . '/app/Jobs/BatchJob.php';

        $this->assertContains('ImportJob.php:7 hypervel.batchableJobChecksCancellation', $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($batchJobPath, str_replace("{\n    }", "{\n        if (\$this->batch()?->cancelled()) {\n            return;\n        }\n    }", file_get_contents($batchJobPath)));

        $this->assertNotContains('ImportJob.php:7 hypervel.batchableJobChecksCancellation', $this->diagnostics($this->analyse($applicationPath), 1));
    }
}
