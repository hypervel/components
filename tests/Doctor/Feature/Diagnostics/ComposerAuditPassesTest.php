<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\ComposerAuditPasses;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ComposerAuditPassesTest extends TestCase
{
    private string $basePath;

    /**
     * Create an isolated Composer directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorComposerAudit');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath, 0775, true);
        $this->app->setBasePath($this->basePath);
    }

    /**
     * Remove the Composer fixture.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testReportsComposerAuditAdvisories(): void
    {
        file_put_contents($this->basePath . '/composer.lock', '{}');

        Process::fake([
            '*' => Process::result(output: json_encode([
                'advisories' => [
                    'vendor/package' => [
                        ['advisoryId' => 'CVE-0000-0000'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), exitCode: 1),
        ]);

        $result = (new ComposerAuditPasses)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('composer-audit-passes.vulnerable', $result->code);
        $this->assertStringContainsString('1 security advisory', $result->details);
    }

    #[DataProvider('auditReports')]
    public function testDistinguishesPolicyFindingsFromExecutionFailures(array|string $report, int $exitCode, string $status, string $code, ?string $details): void
    {
        file_put_contents($this->basePath . '/composer.lock', '{}');
        Process::fake(['*' => Process::result(
            output: is_array($report) ? json_encode($report, JSON_THROW_ON_ERROR) : $report,
            exitCode: $exitCode,
        )]);

        $result = (new ComposerAuditPasses)->check();

        $this->assertSame($status, $result->status->value);
        $this->assertSame('composer-audit-passes.' . $code, $result->code);
        $this->assertSame($details, $result->details);
    }

    /**
     * Provide completed reports and execution failures.
     */
    public static function auditReports(): array
    {
        return [
            'abandoned' => [
                ['advisories' => [], 'abandoned' => ['vendor/old' => 'vendor/replacement']],
                1, 'fail', 'policy-failed', '- vendor/old is abandoned. Suggested replacement: vendor/replacement.',
            ],
            'filter' => [
                ['advisories' => [], 'filter' => ['vendor/package' => [['listName' => 'malware', 'reason' => 'Compromised release.']]]],
                1, 'fail', 'policy-failed', '- vendor/package matches filter list [malware]. Compromised release.',
            ],
            'execution failure' => ['Repository unavailable.', 1, 'fail', 'audit-failed', 'Repository unavailable.'],
            'report only' => [
                ['advisories' => [], 'abandoned' => ['vendor/old' => null]],
                0, 'pass', 'clean', null,
            ],
        ];
    }

    public function testSkipsWithoutALockFile(): void
    {
        Process::fake();

        $this->assertSame('composer-audit-passes.lock-missing', (new ComposerAuditPasses)->check()->code);
        Process::assertNothingRan();
    }
}
