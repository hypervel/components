<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Support;

use Closure;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Hypervel\Doctor\DiagnosticSource;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticFixOutcome;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticReport;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\DatabaseDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\DefaultMetadataDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\FixableDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\FixesSharedStateDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\LocalOnlyFixableDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\NoticeDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\OptionFixableDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\PackagedDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\PassingDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\SharedStateWasFixedDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\ThrowingDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\ThrowingFixableDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\ThrowingFixDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\WarningDiagnostic;
use Hypervel\Tests\Doctor\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Command\Command;

class DoctorTest extends TestCase
{
    public function testRegistersDiagnostics(): void
    {
        $doctor = new Doctor($this->app);

        $doctor->diagnostic(PassingDiagnostic::class);

        $this->assertSame([PassingDiagnostic::class], $doctor->registered());
        $this->assertTrue($doctor->hasDiagnostics());
    }

    public function testRejectsClassesThatAreNotDiagnostics(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Doctor($this->app))->diagnostic(stdClass::class);
    }

    public function testResolvesDiagnosticSourcesFromTheClassFile(): void
    {
        $this->assertSame('hypervel/components', DiagnosticSource::resolve(PassingDiagnostic::class)->package);
        $this->assertSame('symfony/console', DiagnosticSource::resolve(Command::class)->package);
        $this->assertSame('tests/Doctor/Fixtures/Diagnostics/PassingDiagnostic.php', DiagnosticSource::resolve(PassingDiagnostic::class)->relativeFile);
        $this->assertTrue(DiagnosticSource::resolve(PassingDiagnostic::class)->application);
        $this->assertFalse(DiagnosticSource::resolve(ApplicationKeyIsSet::class)->application);
        $this->assertFalse(DiagnosticSource::resolve(Command::class)->application);
        $this->assertSame('hypervel/components', DiagnosticSource::for(new PassingDiagnostic)->label());
        $this->assertSame('hypervel/components', (new PassingDiagnostic)->package());
        $this->assertTrue(DiagnosticSource::for(new PassingDiagnostic)->application);
        $this->assertSame('vendor/package', DiagnosticSource::for(new PackagedDiagnostic)->label());
        $this->assertFalse(DiagnosticSource::for(new PackagedDiagnostic)->application);
    }

    public function testRunsRegisteredDiagnostics(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            WarningDiagnostic::class,
        ]);

        $report = $doctor->only('testing')->run();

        $this->assertCount(2, $report->diagnostics());
        $this->assertTrue($report->hasWarnings());
        $this->assertFalse($report->hasFailures());
        $this->assertSame('Re-run this diagnostic after addressing the warning.', $report->diagnostics()[1]->result->remediation);
    }

    public function testRunsDiagnosticsInFirstSeenGroupOrder(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
            WarningDiagnostic::class,
        ]);

        $groups = $doctor->selectedByGroup();
        $expected = [
            PassingDiagnostic::class,
            WarningDiagnostic::class,
            DatabaseDiagnostic::class,
        ];

        $this->assertSame(['testing', 'database'], array_keys($groups));
        $this->assertSame($expected, array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic::class,
            [...$groups['testing'], ...$groups['database']],
        ));
        $this->assertSame($expected, array_map(
            static fn (DiagnosticOutcome $outcome): string => $outcome->diagnostic::class,
            $doctor->run()->diagnostics(),
        ));
    }

    public function testDoesNotTreatNoticesAsWarnings(): void
    {
        $doctor = (new Doctor($this->app))->diagnostic(NoticeDiagnostic::class);

        $report = $doctor->run();

        $this->assertSame('notice', $report->diagnostics()[0]->result->status->value);
        $this->assertFalse($report->hasWarnings());
        $this->assertFalse($report->hasFailures());
    }

    public function testDefaultsDiagnosticMetadataFromTheClassName(): void
    {
        $diagnostic = new DefaultMetadataDiagnostic;

        $this->assertSame('Default metadata diagnostic', $diagnostic->name);
        $this->assertSame('default-metadata-diagnostic', $diagnostic->group);
    }

    public function testRunsDiagnosticsInRegistrationOrder(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            PackagedDiagnostic::class,
        ]);

        $report = $doctor->run();

        $this->assertCount(2, $report->diagnostics());
        $this->assertSame(PassingDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
        $this->assertSame(PackagedDiagnostic::class, $report->diagnostics()[1]->diagnostic::class);
    }

    public function testConvertsDiagnosticExceptionsIntoErrorResults(): void
    {
        $doctor = (new Doctor($this->app))->diagnostic(ThrowingDiagnostic::class);

        $report = $doctor->run();
        $outcome = $report->diagnostics()[0];

        $this->assertSame(ThrowingDiagnostic::class, $outcome->diagnostic::class);
        $this->assertSame('error', $outcome->result->status->value);
        $this->assertSame('The diagnostic exploded.', $outcome->result->summary);
        $this->assertTrue($report->hasFailures());
    }

    public function testRunsAFixSuppliedByTheDiagnostic(): void
    {
        $doctor = (new Doctor($this->app))->diagnostic(FixableDiagnostic::class);

        $outcome = $doctor->run()->diagnostics()[0];

        $this->assertInstanceOf(Fixable::class, $outcome->diagnostic);
        $this->assertTrue($outcome->fixable());
        $this->assertSame('Fix the testing diagnostic?', $outcome->result->confirmation);
        $this->assertSame('Apply the testing diagnostic fix.', $outcome->result->remediation);

        $fix = $doctor->fix($outcome);

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame('The diagnostic was fixed.', $fix->result->summary);
    }

    public function testDeterminesWhetherAResultIsFixableInAnEnvironmentMode(): void
    {
        $this->assertFalse(DiagnosticResult::fail('Unmarked')->fixableIn(EnvironmentMode::Local));
        $this->assertTrue(DiagnosticResult::fail('Unscoped')->fixable()->fixableIn(EnvironmentMode::Local));
        $this->assertTrue(DiagnosticResult::fail('Unscoped')->fixable()->fixableIn(EnvironmentMode::Production));
        $this->assertTrue(DiagnosticResult::fail('Local')->fixable(EnvironmentMode::Local)->fixableIn(EnvironmentMode::Local));
        $this->assertFalse(DiagnosticResult::fail('Local')->fixable(EnvironmentMode::Local)->fixableIn(EnvironmentMode::Production));
    }

    public function testResolvesLocalOnlyFixabilityAgainstTheCurrentEnvironmentMode(): void
    {
        $outcome = new DiagnosticOutcome(
            new LocalOnlyFixableDiagnostic,
            (new LocalOnlyFixableDiagnostic)->check(),
        );

        $this->app->detectEnvironment(fn (): string => 'local');

        $this->assertTrue($outcome->fixable());

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertFalse($outcome->fixable());

        $this->app->detectEnvironment(fn (): string => 'preview');

        $this->assertFalse($outcome->fixable());
    }

    public function testSkipsLocalOnlyFixesOutsideLocalMode(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $offered = false;
        $report = (new Doctor($this->app))
            ->diagnostic(LocalOnlyFixableDiagnostic::class)
            ->fixUsing(function (DiagnosticOutcome $outcome) use (&$offered): bool {
                $offered = true;

                return true;
            })
            ->run();

        $this->assertSame([], $report->fixes());
        $this->assertTrue($report->hasFailures());
        $this->assertFalse($offered);
    }

    public function testAppliesLocalOnlyFixesInLocalMode(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $report = (new Doctor($this->app))
            ->diagnostic(LocalOnlyFixableDiagnostic::class)
            ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
            ->run();

        $this->assertCount(1, $report->fixes());
        $this->assertSame('The local diagnostic was fixed.', $report->fixes()[0]->result->summary);
    }

    public function testRejectsDirectFixesForOutcomesBlockedInTheCurrentEnvironment(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('is not fixable');
        $this->app->detectEnvironment(fn (): string => 'production');

        (new Doctor($this->app))->fix(new DiagnosticOutcome(
            new LocalOnlyFixableDiagnostic,
            (new LocalOnlyFixableDiagnostic)->check(),
        ));
    }

    public function testKeepsUnscopedOutcomesFixableInProductionMode(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $outcome = (new Doctor($this->app))
            ->diagnostic(FixableDiagnostic::class)
            ->run()
            ->diagnostics()[0];

        $this->assertTrue($outcome->fixable());
    }

    public function testAppliesAFixWithTheOptionChosenByTheCallback(): void
    {
        $doctor = (new Doctor($this->app))->diagnostic(OptionFixableDiagnostic::class);

        $report = $doctor
            ->fixUsing(fn (DiagnosticOutcome $outcome): string => 'a')
            ->run();

        $this->assertCount(1, $report->fixes());
        $this->assertSame('The option diagnostic was fixed with [a].', $report->fixes()[0]->result->summary);
        $this->assertSame('a', config('doctor-testing.option-fixed'));
        $this->assertFalse($report->hasFailures());
    }

    public function testSkipsOptionFixesDeclinedByTheCallback(): void
    {
        $doctor = (new Doctor($this->app))->diagnostic(OptionFixableDiagnostic::class);

        $report = $doctor
            ->fixUsing(fn (DiagnosticOutcome $outcome): bool => false)
            ->run();

        $this->assertSame([], $report->fixes());
        $this->assertTrue($report->hasFailures());
        $this->assertNull(config('doctor-testing.option-fixed'));
    }

    public function testRejectsPlainApprovalForOutcomesThatRequireAFixOption(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('requires a fix option');
        (new Doctor($this->app))
            ->diagnostic(OptionFixableDiagnostic::class)
            ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
            ->run();
    }

    public function testRejectsUndeclaredFixOptionValues(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('is not declared');
        (new Doctor($this->app))->fix(new DiagnosticOutcome(
            new OptionFixableDiagnostic,
            (new OptionFixableDiagnostic)->check(),
        ), 'nope');
    }

    public function testRejectsFixOptionsForOutcomesThatDoNotDeclareThem(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('does not accept a fix option');
        (new Doctor($this->app))->fix(new DiagnosticOutcome(
            new FixableDiagnostic,
            (new FixableDiagnostic)->check(),
        ), 'a');
    }

    public function testDeterminesWhetherAFixRequiresAnOption(): void
    {
        $plain = new DiagnosticOutcome(new FixableDiagnostic, (new FixableDiagnostic)->check());
        $options = new DiagnosticOutcome(new OptionFixableDiagnostic, (new OptionFixableDiagnostic)->check());
        $scoped = new DiagnosticOutcome(
            new OptionFixableDiagnostic,
            DiagnosticResult::fail('The option diagnostic failed.')
                ->fixable(EnvironmentMode::Local)
                ->fixOptions(['a' => 'Option A']),
        );

        $this->assertFalse($plain->fixRequiresOption());
        $this->assertTrue($options->fixRequiresOption());

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertFalse($scoped->fixRequiresOption());
    }

    public function testTurnsFixExceptionsIntoHelpfulErrorResults(): void
    {
        $fix = (new Doctor($this->app))->fix(new DiagnosticOutcome(
            new ThrowingFixDiagnostic,
            DiagnosticResult::fail('The diagnostic failed.')->fixable(),
        ));

        $this->assertSame('error', $fix->result->status->value);
        $this->assertSame('Failed to fix Testing diagnostic fix throws: permission denied', $fix->result->summary);
    }

    public function testRejectsFixesForDiagnosticsThatAreNotFixable(): void
    {
        $this->expectException(LogicException::class);
        (new Doctor($this->app))->fix(new DiagnosticOutcome(
            new PassingDiagnostic,
            DiagnosticResult::fail('The diagnostic failed.'),
        ));
    }

    public function testRejectsFixesForOutcomesThatAreNotExplicitlyFixable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('is not fixable');
        (new Doctor($this->app))->fix(new DiagnosticOutcome(
            new FixableDiagnostic,
            DiagnosticResult::fail('The diagnostic failed.'),
        ));
    }

    public function testRejectsFixesForErrorOutcomesEvenWhenMarkedFixable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('is not fixable');
        (new Doctor($this->app))->fix(new DiagnosticOutcome(
            new FixableDiagnostic,
            DiagnosticResult::error('The diagnostic errored.')->fixable(),
        ));
    }

    public function testDoesNotOfferAnAutomaticFixAfterAFixableDiagnosticThrows(): void
    {
        $doctor = (new Doctor($this->app))->diagnostic(ThrowingFixableDiagnostic::class);
        $offered = false;

        $report = $doctor
            ->fixUsing(function (DiagnosticOutcome $outcome) use (&$offered): bool {
                $offered = true;

                return true;
            })
            ->run();

        $this->assertSame('error', $report->diagnostics()[0]->result->status->value);
        $this->assertFalse($report->diagnostics()[0]->fixable());
        $this->assertSame([], $report->fixes());
        $this->assertFalse($offered);
    }

    public function testAppliesConfiguredSelectorsToProgrammaticRuns(): void
    {
        config(['doctor.only' => ['database']]);

        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
        ]);

        $report = $doctor->run();

        $this->assertCount(1, $report->diagnostics());
        $this->assertSame(DatabaseDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
    }

    public function testConstrainsRunsWithFluentOnlyAndExceptSelectors(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
            PackagedDiagnostic::class,
        ]);

        $report = $doctor
            ->only('testing')
            ->except('vendor/package')
            ->run();

        $this->assertCount(1, $report->diagnostics());
        $this->assertSame(PassingDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
    }

    public function testIntersectsRepeatedFluentOnlyConstraints(): void
    {
        config(['doctor.only' => ['testing']]);

        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
            PackagedDiagnostic::class,
        ]);

        $report = $doctor
            ->only(PassingDiagnostic::class, DatabaseDiagnostic::class)
            ->run();

        $this->assertCount(1, $report->diagnostics());
        $this->assertSame(PassingDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
    }

    public function testBailsAfterTheFirstFailingDiagnostic(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            WarningDiagnostic::class,
            FixableDiagnostic::class,
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
        ]);

        $report = $doctor->bail()->run();

        $this->assertSame([
            WarningDiagnostic::class,
            FixableDiagnostic::class,
        ], array_map(
            static fn (DiagnosticOutcome $outcome): string => $outcome->diagnostic::class,
            $report->diagnostics(),
        ));
    }

    public function testTreatsDiagnosticErrorsAsFailuresWhenBailing(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            ThrowingDiagnostic::class,
            PassingDiagnostic::class,
        ]);

        $report = $doctor->bail()->run();

        $this->assertCount(1, $report->diagnostics());
        $this->assertSame(ThrowingDiagnostic::class, $report->diagnostics()[0]->diagnostic::class);
        $this->assertSame('error', $report->diagnostics()[0]->result->status->value);
    }

    public function testAppliesApprovedFixesAndRerunsTheDiagnostics(): void
    {
        config(['doctor-testing.shared-state-fixed' => false]);

        $doctor = (new Doctor($this->app))->diagnostics([
            FixesSharedStateDiagnostic::class,
            SharedStateWasFixedDiagnostic::class,
        ]);

        $rerunning = false;

        $report = $doctor
            ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
            ->beforeRerun(function () use (&$rerunning): void {
                $rerunning = true;
            })
            ->run();

        $this->assertCount(1, $report->fixes());
        $this->assertSame('The shared state fix ran.', $report->fixes()[0]->result->summary);
        $this->assertFalse($report->hasFailures());
        $this->assertTrue($rerunning);
    }

    public function testDoesNotRerunDiagnosticsWhenFixesAreDeclined(): void
    {
        config(['doctor-testing.shared-state-fixed' => false]);

        $doctor = (new Doctor($this->app))->diagnostic(FixesSharedStateDiagnostic::class);

        $rerunning = false;

        $report = $doctor
            ->fixUsing(fn (DiagnosticOutcome $outcome): bool => false)
            ->beforeRerun(function () use (&$rerunning): void {
                $rerunning = true;
            })
            ->run();

        $this->assertSame([], $report->fixes());
        $this->assertTrue($report->hasFailures());
        $this->assertFalse($rerunning);
    }

    public function testOwnsDiagnosticExecutionWhileAllowingProgressToBeObserved(): void
    {
        $doctor = (new Doctor($this->app))->diagnostics([
            PassingDiagnostic::class,
            DatabaseDiagnostic::class,
            ThrowingDiagnostic::class,
        ]);

        $observed = [];

        $report = $doctor
            ->observeUsing(function (string $group, Closure $next) use (&$observed): void {
                $observed[] = 'group:' . $group;

                $next(
                    before: function (Diagnostic $diagnostic) use (&$observed): void {
                        $observed[] = 'checking:' . class_basename($diagnostic);
                    },
                    after: function (DiagnosticOutcome $outcome) use (&$observed): void {
                        $observed[] = 'result:' . $outcome->result->status->value;
                    },
                );
            })
            ->run();

        $this->assertCount(3, $report->diagnostics());
        $this->assertSame('error', $report->diagnostics()[1]->result->status->value);
        $this->assertSame([
            'group:testing',
            'checking:PassingDiagnostic',
            'result:pass',
            'checking:ThrowingDiagnostic',
            'result:error',
            'group:database',
            'checking:DatabaseDiagnostic',
            'result:pass',
        ], $observed);
    }

    public function testRequiresProgressObserversToContinueRunnerOwnedExecution(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('must invoke its continuation');
        (new Doctor($this->app))
            ->diagnostic(PassingDiagnostic::class)
            ->observeUsing(function (string $group, Closure $next): void {
                // Intentionally do not continue the runner-owned execution.
            })
            ->run();
    }

    public function testAppendsFixesToAnExistingDiagnosticReport(): void
    {
        $diagnostic = new PassingDiagnostic;
        $first = new DiagnosticFixOutcome($diagnostic, FixResult::pass('The first fix passed.'));
        $second = new DiagnosticFixOutcome($diagnostic, FixResult::pass('The second fix passed.'));

        $report = (new DiagnosticReport)->withFixes([$first])->withFixes([$second]);

        $this->assertSame([$first, $second], $report->fixes());
    }

    public function testDiagnosticInstanceStateIsFreshForPostFixAndLaterRuns(): void
    {
        config(['doctor-testing.shared-state-fixed' => false]);
        $doctor = (new Doctor($this->app))->diagnostic(MemoizingDoctorDiagnostic::class);

        $report = $doctor->fixUsing(fn (): bool => true)->run();

        $this->assertFalse($report->hasFailures());
        $this->assertNotSame($report->fixes()[0]->diagnostic, $report->diagnostics()[0]->diagnostic);

        config(['doctor-testing.shared-state-fixed' => false]);

        $this->assertTrue($doctor->run()->hasFailures());
    }

    #[DataProvider('canceledOperations')]
    public function testCancellationPropagatesUnchanged(string $operation): void
    {
        $cancellation = new CanceledException('Doctor operation canceled');
        $diagnostic = new class($cancellation) extends Diagnostic implements Fixable {
            /**
             * Capture the cancellation raised by diagnostic work.
             */
            public function __construct(private CanceledException $cancellation)
            {
                parent::__construct();
            }

            /**
             * Simulate a canceled check.
             */
            public function check(): DiagnosticResult
            {
                throw $this->cancellation;
            }

            /**
             * Simulate a canceled repair.
             */
            public function fix(DiagnosticResult $result, ?string $option = null): FixResult
            {
                throw $this->cancellation;
            }
        };
        $doctor = new Doctor($this->app);

        try {
            match ($operation) {
                'check' => $doctor->check($diagnostic),
                'fix' => $doctor->fix(new DiagnosticOutcome($diagnostic, DiagnosticResult::fail('Repair required')->fixable())),
            };
            $this->fail('The operation must propagate cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    /**
     * Provide the runner's diagnostic entry points.
     */
    public static function canceledOperations(): array
    {
        return [['check'], ['fix']];
    }
}

class MemoizingDoctorDiagnostic extends FixesSharedStateDiagnostic
{
    private ?DiagnosticResult $result = null;

    /**
     * Retain a result for this diagnostic invocation.
     */
    public function check(): DiagnosticResult
    {
        return $this->result ??= parent::check();
    }
}
