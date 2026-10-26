<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Support;

use Hypervel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Hypervel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored;
use Hypervel\Doctor\DiagnosticSelection;
use Hypervel\Doctor\Doctor;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\DatabaseDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\HypervelPackageDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\PackagedDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\PassingDiagnostic;
use Hypervel\Tests\Doctor\TestCase;

class DiagnosticSelectionTest extends TestCase
{
    public function testFiltersDiagnosticsByGroup(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
        ]);

        $report = $doctor->only('database')->run();

        $this->assertCount(1, $report->diagnostics());
        $this->assertSame(DatabaseDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
    }

    public function testFiltersDiagnosticsByClassNameAndExcludesGroups(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
        ]);

        $report = $doctor
            ->only('PassingDiagnostic,DatabaseDiagnostic')
            ->except('database')
            ->run();

        $this->assertCount(1, $report->diagnostics());
        $this->assertSame(PassingDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
    }

    public function testFiltersDiagnosticsByResolvedPackage(): void
    {
        $diagnostic = new PackagedDiagnostic;

        $this->assertTrue(DiagnosticSelection::make(only: ['vendor/package'])->matches($diagnostic));
        $this->assertFalse(DiagnosticSelection::make(except: ['vendor/package'])->matches($diagnostic));
    }

    public function testFiltersDiagnosticsByWildcardPackageSelectors(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            PackagedDiagnostic::class,
            HypervelPackageDiagnostic::class,
        ]);

        $report = $doctor->only('hypervel/*')->run();

        $this->assertCount(2, $report->diagnostics());
        $this->assertSame(PassingDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
        $this->assertSame(HypervelPackageDiagnostic::class, $report->diagnostics()[1]->diagnostic::class);
    }

    public function testFiltersBundledDoctorDiagnosticsByPackageSelector(): void
    {
        $this->assertTrue(DiagnosticSelection::make(only: ['hypervel/components'])->matches(new ApplicationKeyIsSet));
        $this->assertTrue(DiagnosticSelection::make(only: ['hypervel/*'])->matches(new ApplicationKeyIsSet));
        $this->assertFalse(DiagnosticSelection::make(except: ['hypervel/components'])->matches(new ApplicationKeyIsSet));
    }

    public function testNarrowsDiagnosticsWithAdditionalOnlyConstraints(): void
    {
        $selection = DiagnosticSelection::make(only: ['security'])
            ->constrain(only: ['EnvironmentFileIsGitIgnored']);

        $this->assertTrue($selection->matches(new EnvironmentFileIsGitIgnored));
        $this->assertFalse($selection->matches(new ApplicationKeyIsSet));
    }

    public function testStacksAdditionalExceptSelectors(): void
    {
        $selection = DiagnosticSelection::make(except: ['security'])
            ->constrain(except: ['environment']);

        $this->assertFalse($selection->matches(new EnvironmentFileIsGitIgnored));
        $this->assertFalse($selection->matches(new ApplicationKeyIsSet));
        $this->assertTrue($selection->matches(new DatabaseDiagnostic));
    }
}
