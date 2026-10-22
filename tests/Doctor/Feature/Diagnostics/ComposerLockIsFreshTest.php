<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Closure;
use Hypervel\Doctor\Diagnostics\ComposerLockIsFresh;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ComposerLockIsFreshTest extends TestCase
{
    private string $basePath;

    /**
     * Create an isolated Composer directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorComposerLock');
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

    public function testOffersToGenerateAMissingLockFileLocally(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', '{}');

        $result = (new ComposerLockIsFresh)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('composer-lock-is-fresh.lock-missing', $result->code);
        $this->assertSame('Generate composer.lock and install dependencies using `composer install`?', $result->confirmation);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
        $this->assertFalse($result->fixableIn(EnvironmentMode::Production));
    }

    public function testChecksComposerLockFreshnessThroughComposerCheckLockValidation(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', '{}');
        file_put_contents($basePath . '/composer.lock', '{}');

        Process::fake([
            '*' => Process::result(errorOutput: 'The lock file is not up to date.', exitCode: 1),
        ]);

        $result = (new ComposerLockIsFresh)->check();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'composer',
            'validate',
            '--check-lock',
            '--no-check-publish',
            '--no-check-all',
            '--no-interaction',
            '--no-ansi',
        ]);

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('The lock file is not up to date.', $result->details);
        $this->assertSame('Run `composer update --lock` to refresh the lock file.', $result->remediation);
        $this->assertSame('Refresh composer.lock using `composer update --lock`?', $result->confirmation);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
    }

    public function testReportsConstraintMismatchesWithThePackagesThatNeedUpdating(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', '{}');
        file_put_contents($basePath . '/composer.lock', '{}');

        Process::fake([
            '*' => Process::result(errorOutput: implode(PHP_EOL, [
                './composer.json is valid but your composer.lock has some errors',
                '# Lock file errors',
                '- The lock file is not up to date with the latest changes in composer.json, it is recommended that you run `composer update` or `composer update <package name>`.',
                '- Required package "hypervel/passkeys" is in the lock file as "v0.2.1" but that does not satisfy your constraint "^1.27".',
                '- Required package "hypervel/wayfinder" is not present in the lock file.',
                'This usually happens when composer files are incorrectly merged or the composer.json file is manually edited.',
                'Read more about correctly resolving merge conflicts https://getcomposer.org/doc/articles/resolving-merge-conflicts.md',
                'and prefer using the "require" command over editing the composer.json file directly https://getcomposer.org/doc/03-cli.md#require-r',
            ]), exitCode: 2),
        ]);

        $result = (new ComposerLockIsFresh)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('composer-lock-is-fresh.constraint-mismatch', $result->code);
        $this->assertSame('composer.lock does not satisfy the package constraints in composer.json.', $result->summary);
        $this->assertSame('Run `composer update hypervel/passkeys hypervel/wayfinder` to lock versions that satisfy composer.json.', $result->remediation);
        $this->assertSame('Update hypervel/passkeys hypervel/wayfinder using `composer update hypervel/passkeys hypervel/wayfinder`?', $result->confirmation);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
        $this->assertSame(['hypervel/passkeys', 'hypervel/wayfinder'], $result->context['packages']);
        $this->assertSame(implode(PHP_EOL, [
            '- The lock file is not up to date with the latest changes in composer.json.',
            '- Required package "hypervel/passkeys" is in the lock file as "v0.2.1" but that does not satisfy your constraint "^1.27".',
            '- Required package "hypervel/wayfinder" is not present in the lock file.',
        ]), $result->details);
    }

    public function testDoesNotReportUnrelatedComposerValidationErrorsAsStaleLockFiles(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', '{}');
        file_put_contents($basePath . '/composer.lock', '{}');

        Process::fake([
            '*' => Process::result(errorOutput: 'composer.json is invalid JSON.', exitCode: 2),
        ]);

        $result = (new ComposerLockIsFresh)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('composer-lock-is-fresh.inspection-failed', $result->code);
        $this->assertSame('Composer could not verify composer.lock freshness.', $result->summary);
        $this->assertFalse($result->fixable);
        $this->assertSame('composer.json is invalid JSON.', $result->details);
    }

    #[DataProvider('fixableOutcomes')]
    public function testRunsTheComposerRepairForEachFixableOutcome(Closure $result, array $command): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', '{}');

        Process::fake([
            '*' => Process::result(output: 'Writing lock file'),
        ]);

        $fix = (new ComposerLockIsFresh)->fix($result());

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === $command
            && $process->path === $basePath
            && $process->timeout === null);

        $this->assertSame('pass', $fix->status->value);
        $this->assertSame('composer-lock-is-fresh.fix.fixed', $fix->code);
        $this->assertSame('composer.lock was repaired.', $fix->summary);
    }

    /**
     * Provide Composer repairs for fixable outcomes.
     */
    public static function fixableOutcomes(): array
    {
        return [
            'missing lock file' => [
                fn (): DiagnosticResult => DiagnosticResult::fail('missing', 'composer-lock-is-fresh.lock-missing'),
                ['composer', 'install', '--no-interaction'],
            ],
            'stale lock file' => [
                fn (): DiagnosticResult => DiagnosticResult::fail('stale', 'composer-lock-is-fresh.stale'),
                ['composer', 'update', '--lock', '--no-interaction'],
            ],
            'constraint mismatch' => [
                fn (): DiagnosticResult => DiagnosticResult::fail('mismatch', 'composer-lock-is-fresh.constraint-mismatch')
                    ->withContext('packages', ['hypervel/passkeys', 'hypervel/wayfinder']),
                ['composer', 'update', 'hypervel/passkeys', 'hypervel/wayfinder', '--no-interaction'],
            ],
        ];
    }

    public function testReportsWhenComposerCannotRepairTheLockFile(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'Dependency resolution failed.', exitCode: 2),
        ]);

        $fix = (new ComposerLockIsFresh)->fix(
            DiagnosticResult::fail('stale', 'composer-lock-is-fresh.stale'),
        );

        $this->assertSame('fail', $fix->status->value);
        $this->assertSame('composer-lock-is-fresh.fix.fix-failed', $fix->code);
        $this->assertSame('composer.lock could not be repaired.', $fix->summary);
        $this->assertStringContainsString('Dependency resolution failed.', $fix->details);
    }
}
