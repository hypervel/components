<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Console;

use App\Doctor\Diagnostics\GeneratedFixableDiagnostic;
use App\Doctor\Diagnostics\GeneratedStandardDiagnostic;
use Hypervel\Doctor\Results\Status;
use Hypervel\Support\Facades\File;
use Hypervel\Tests\Doctor\TestCase;

class DiagnosticMakeCommandTest extends TestCase
{
    /**
     * Remove generated diagnostics from the disposable application.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory(app_path('Doctor'));
        } finally {
            parent::tearDown();
        }
    }

    public function testGeneratesADiagnosticClass(): void
    {
        $this->artisan('make:diagnostic', ['name' => 'HorizonIsRunning'])
            ->expectsConfirmation('Should the diagnostic offer a fix?')
            ->assertExitCode(0);

        $source = File::get(app_path('Doctor/Diagnostics/HorizonIsRunning.php'));
        $this->assertStringContainsString('declare(strict_types=1);', $source);
        $this->assertStringContainsString('namespace App\Doctor\Diagnostics;', $source);
        $this->assertStringContainsString('class HorizonIsRunning extends Diagnostic', $source);
        $this->assertStringContainsString('public function check(): DiagnosticResult', $source);
        $this->assertStringNotContainsString('Fixable', $source);
    }

    public function testGeneratesAFixableDiagnosticClass(): void
    {
        $this->artisan('make:diagnostic', ['name' => 'HorizonIsRunning', '--fixable' => true])
            ->assertExitCode(0);

        $source = File::get(app_path('Doctor/Diagnostics/HorizonIsRunning.php'));
        $this->assertStringContainsString('declare(strict_types=1);', $source);
        $this->assertStringContainsString('use Hypervel\Doctor\Contracts\Fixable;', $source);
        $this->assertStringContainsString('class HorizonIsRunning extends Diagnostic implements Fixable', $source);
        $this->assertStringContainsString('->fixable()', $source);
        $this->assertStringContainsString('public function fix(DiagnosticResult $result, ?string $option = null): FixResult', $source);
    }

    public function testPromptsForTheNameAndFixabilityWhenNoInputIsGiven(): void
    {
        $this->artisan('make:diagnostic')
            ->expectsQuestion('What should the diagnostic be named?', 'QueueWorkerIsRunning')
            ->expectsConfirmation('Should the diagnostic offer a fix?', 'yes')
            ->assertExitCode(0);

        $this->assertStringContainsString('class QueueWorkerIsRunning extends Diagnostic implements Fixable', File::get(app_path('Doctor/Diagnostics/QueueWorkerIsRunning.php')));
    }

    public function testDoesNotPromptForFixabilityWithoutAnInteractiveTerminal(): void
    {
        $this->artisan('make:diagnostic', ['name' => 'MailerIsConfigured', '--no-interaction' => true])
            ->assertExitCode(0);

        $this->assertStringNotContainsString('Fixable', File::get(app_path('Doctor/Diagnostics/MailerIsConfigured.php')));
    }

    public function testDoesNotOverwriteAnExistingDiagnosticWithoutForce(): void
    {
        $this->artisan('make:diagnostic', ['name' => 'ExistingDiagnostic'])
            ->expectsConfirmation('Should the diagnostic offer a fix?')
            ->assertExitCode(0);

        File::put(app_path('Doctor/Diagnostics/ExistingDiagnostic.php'), '<?php // original');

        $this->artisan('make:diagnostic', ['name' => 'ExistingDiagnostic', '--fixable' => true])
            ->expectsOutputToContain('already exists');

        $this->assertSame('<?php // original', File::get(app_path('Doctor/Diagnostics/ExistingDiagnostic.php')));
    }

    public function testGeneratesADiagnosticThatRuns(): void
    {
        $this->artisan('make:diagnostic', ['name' => 'GeneratedStandardDiagnostic'])
            ->expectsConfirmation('Should the diagnostic offer a fix?')
            ->assertExitCode(0);

        require app_path('Doctor/Diagnostics/GeneratedStandardDiagnostic.php');

        $diagnostic = new GeneratedStandardDiagnostic;

        $this->assertSame('Generated standard diagnostic', $diagnostic->name);
        $this->assertSame('generated-standard-diagnostic', $diagnostic->group);
        $this->assertSame(Status::Pass, $diagnostic->check()->status);
    }

    public function testGeneratesAFixableDiagnosticThatRunsAndFixes(): void
    {
        $this->artisan('make:diagnostic', ['name' => 'GeneratedFixableDiagnostic', '--fixable' => true])
            ->assertExitCode(0);

        require app_path('Doctor/Diagnostics/GeneratedFixableDiagnostic.php');

        $diagnostic = new GeneratedFixableDiagnostic;
        $result = $diagnostic->check();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertTrue($result->fixable);
        $this->assertSame('Apply the fix?', $result->confirmation);
        $this->assertSame(Status::Pass, $diagnostic->fix($result)->status);
    }
}
