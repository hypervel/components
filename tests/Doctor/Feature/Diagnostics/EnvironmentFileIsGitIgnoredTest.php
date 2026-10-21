<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\Link;
use Hypervel\Process\FakeProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EnvironmentFileIsGitIgnoredTest extends TestCase
{
    private string $repositoryPath;

    private string $basePath;

    /**
     * Configure local maintenance behavior.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->detectEnvironment(fn (): string => 'local');
    }

    /**
     * Create an isolated Git repository with a nested application.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryPath = ParallelTesting::tempDir('DoctorEnvironmentGit');
        $this->basePath = $this->repositoryPath . '/application';
        File::deleteDirectory($this->repositoryPath);
        File::makeDirectory($this->basePath, 0775, true);
        Process::path($this->repositoryPath)->timeout(10)->run(['git', 'init', '--quiet'])->throw();
        $this->app->useEnvironmentPath($this->basePath);
        $this->app->loadEnvironmentFrom('.env');
        touch($this->basePath . '/.env');
    }

    /**
     * Remove the isolated repository.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->repositoryPath);
        } finally {
            parent::tearDown();
        }
    }

    public function testPassesWhenEnvironmentFilesAreGitignored(): void
    {
        $basePath = $this->basePath;

        file_put_contents($basePath . '/.gitignore', ".env*\n");

        $result = (new EnvironmentFileIsGitIgnored)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testAddsEnvToAnExistingGitignoreWhenFixed(): void
    {
        $basePath = $this->basePath;

        file_put_contents($basePath . '/.gitignore', "/vendor\n");

        $diagnostic = new EnvironmentFileIsGitIgnored;
        $result = $diagnostic->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertTrue($result->fixable);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
        $this->assertSame("Add an ignore rule for [{$basePath}/.env] to [{$basePath}/.gitignore]?", $result->confirmation);
        $this->assertEquals([Link::docs('configuration', 'environment-configuration')], $result->links);

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome($diagnostic, $result));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame("The environment file [{$basePath}/.env] is now ignored by Git.", $fix->result->summary);
        $this->assertSame("/vendor\n/.env\n", file_get_contents($basePath . '/.gitignore'));
        $this->assertSame('pass', $diagnostic->check()->status->value);
    }

    public function testCreatesAGitignoreThatIgnoresEnvWhenFixed(): void
    {
        $basePath = $this->basePath;

        $diagnostic = new EnvironmentFileIsGitIgnored;
        $result = $diagnostic->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertTrue($result->fixable);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
        $this->assertSame("Create [{$basePath}/.gitignore] to ignore [{$basePath}/.env]?", $result->confirmation);
        $this->assertEquals([Link::docs('configuration', 'environment-configuration')], $result->links);

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome($diagnostic, $result));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame("/.env\n", file_get_contents($basePath . '/.gitignore'));
        $this->assertSame('pass', $diagnostic->check()->status->value);
    }

    public function testDoesNotOfferToUpdateGitignoreInProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $basePath = $this->basePath;
        file_put_contents($basePath . '/.gitignore', "/vendor\n");

        $outcome = $this->app->make(Doctor::class)
            ->only('EnvironmentFileIsGitIgnored')->run()
            ->diagnostics()[0];

        $this->assertSame('fail', $outcome->result->status->value);
        $this->assertFalse($outcome->fixable());
        $this->assertSame("Add a rule for [{$basePath}/.env] to [{$basePath}/.gitignore] so secrets are not committed.", $outcome->result->remediation);
    }

    public function testTrackedFilesFailEvenWithAMatchingIgnoreRule(): void
    {
        Process::path($this->basePath)->timeout(10)->run(['git', 'add', '--', '.env'])->throw();
        file_put_contents($this->basePath . '/.gitignore', ".env\n");
        $diagnostic = new EnvironmentFileIsGitIgnored;

        $result = $diagnostic->check();

        $this->assertSame('environment-file-is-git-ignored.tracked', $result->code);
        $this->assertFalse($result->fixable);
        $this->assertSame('fail', $diagnostic->fix($result)->status->value);
        $this->assertSame(".env\n", file_get_contents($this->basePath . '/.gitignore'));
    }

    public function testHonorsAncestorIgnoreRulesAndLocalNegation(): void
    {
        file_put_contents($this->repositoryPath . '/.gitignore', ".env\n");
        $diagnostic = new EnvironmentFileIsGitIgnored;
        $this->assertSame('pass', $diagnostic->check()->status->value);

        file_put_contents($this->basePath . '/.gitignore', "!.env\n");
        $result = $diagnostic->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('pass', $diagnostic->fix($result)->status->value);
        $this->assertSame("!.env\n/.env\n", file_get_contents($this->basePath . '/.gitignore'));
    }

    #[DataProvider('selectedFilenames')]
    public function testUsesLiteralSelectedFilenamesForTrackingAndIgnoreRules(string $filename): void
    {
        file_put_contents($this->basePath . '/.env.other', 'example');
        Process::path($this->basePath)->timeout(10)->run(['git', 'add', '--', '.env.other'])->throw();
        $this->app->loadEnvironmentFrom($filename);
        touch($this->basePath . '/' . $filename);
        $diagnostic = new EnvironmentFileIsGitIgnored;

        $result = $diagnostic->check();

        $this->assertTrue($result->fixable, $result->summary);
        $this->assertSame('pass', $diagnostic->fix($result)->status->value);
        $this->assertSame('pass', $diagnostic->check()->status->value);
        $this->assertSame('example', file_get_contents($this->basePath . '/.env.other'));
    }

    /**
     * Provide filenames with Git pattern characters.
     */
    public static function selectedFilenames(): array
    {
        return [['.env.custom'], ['.env*'], ['.env\literal']];
    }

    #[DataProvider('inspectionCommands')]
    public function testReportsIndexAndIgnoreInspectionFailures(string $command): void
    {
        Process::fake(function (PendingProcess $process) use ($command): FakeProcessResult {
            if (in_array($command, $process->command, true)) {
                return Process::result(errorOutput: 'Git inspection failed', exitCode: 128);
            }

            return in_array('rev-parse', $process->command, true)
                ? Process::result(output: 'true')
                : Process::result(exitCode: 1);
        });

        $result = (new EnvironmentFileIsGitIgnored)->check();

        $this->assertSame('error', $result->status->value);
        $this->assertSame('Git inspection failed', $result->details);
        $this->assertFalse($result->fixable);
    }

    /**
     * Provide the commands that inspect tracking and ignoring.
     */
    public static function inspectionCommands(): array
    {
        return [['ls-files'], ['check-ignore']];
    }

    public function testDoesNotClaimRepairSuccessWhenGitStillReportsTheFileUnprotected(): void
    {
        Process::fake(fn (PendingProcess $process): FakeProcessResult => in_array('rev-parse', $process->command, true)
                ? Process::result(output: 'true')
                : Process::result(exitCode: 1));
        $diagnostic = new EnvironmentFileIsGitIgnored;

        $result = $diagnostic->fix($diagnostic->check());

        $this->assertSame('fail', $result->status->value);
        $this->assertSame("/.env\n", file_get_contents($this->basePath . '/.gitignore'));
    }

    #[DataProvider('gitFailures')]
    public function testDistinguishesMissingGitRepositoriesAndInspectionErrors(int $exitCode, string $output, string $errorOutput, string $status, string $code): void
    {
        Process::fake(['*' => Process::result(output: $output, errorOutput: $errorOutput, exitCode: $exitCode)]);

        $result = (new EnvironmentFileIsGitIgnored)->check();

        $this->assertSame($status, $result->status->value);
        $this->assertSame('environment-file-is-git-ignored.' . $code, $result->code);
    }

    /**
     * Provide distinct Git command failure modes.
     */
    public static function gitFailures(): array
    {
        return [
            'missing executable' => [127, '', 'git: not found', 'skip', 'git-missing'],
            'not repository' => [128, '', 'fatal: not a git repository', 'skip', 'not-repository'],
            'not work tree' => [0, 'false', '', 'skip', 'not-repository'],
            'unsafe ownership' => [128, '', 'fatal: detected dubious ownership in repository', 'error', 'inspection-failed'],
        ];
    }
}
