<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\ComposerAuditPasses;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

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
}
