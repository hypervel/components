<?php

declare(strict_types=1);

namespace Hypervel\Doctor;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Results\DiagnosticFixOutcome;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticReport;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * @phpstan-import-type GroupContinuation from PendingRun
 */
class Doctor
{
    /**
     * The registered diagnostic classes.
     *
     * @var array<class-string<Diagnostic>, class-string<Diagnostic>>
     */
    protected array $diagnostics = [];

    /**
     * Create a new Doctor instance.
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Register a diagnostic.
     *
     * Boot-only. The registry belongs to the shared Doctor service and affects subsequent runs.
     *
     * @param class-string $diagnostic
     */
    public function diagnostic(string $diagnostic): self
    {
        if (! is_subclass_of($diagnostic, Diagnostic::class)) {
            throw new InvalidArgumentException('Diagnostics must extend [' . Diagnostic::class . '].');
        }

        $this->diagnostics[$diagnostic] = $diagnostic;

        return $this;
    }

    /**
     * Register multiple diagnostics.
     *
     * Boot-only. The registry belongs to the shared Doctor service and affects subsequent runs.
     *
     * @param iterable<class-string> $diagnostics
     */
    public function diagnostics(iterable $diagnostics): self
    {
        foreach ($diagnostics as $diagnostic) {
            $this->diagnostic($diagnostic);
        }

        return $this;
    }

    /**
     * Get the registered diagnostic classes.
     *
     * @return list<class-string<Diagnostic>>
     */
    public function registered(): array
    {
        return array_values($this->diagnostics);
    }

    /**
     * Determine whether any diagnostics are registered.
     */
    public function hasDiagnostics(): bool
    {
        return $this->diagnostics !== [];
    }

    /**
     * Run the registered diagnostics.
     */
    public function run(): DiagnosticReport
    {
        return $this->pendingRun()->run();
    }

    /**
     * Begin a run constrained to diagnostics matching the given selectors.
     *
     * Selectors may reference a diagnostic class, class basename, group, or
     * package name.
     */
    public function only(string ...$selectors): PendingRun
    {
        return $this->pendingRun()->only(...$selectors);
    }

    /**
     * Begin a run that excludes diagnostics matching the given selectors.
     */
    public function except(string ...$selectors): PendingRun
    {
        return $this->pendingRun()->except(...$selectors);
    }

    /**
     * Begin a run that stops after the first failing diagnostic.
     */
    public function bail(bool $bail = true): PendingRun
    {
        return $this->pendingRun()->bail($bail);
    }

    /**
     * Begin a run that observes each diagnostic group through the given callback.
     *
     * @param Closure(string, GroupContinuation): void $callback
     */
    public function observeUsing(Closure $callback): PendingRun
    {
        return $this->pendingRun()->observeUsing($callback);
    }

    /**
     * Begin a run that applies fixes approved by the given callback.
     *
     * @param Closure(DiagnosticOutcome): (bool|string) $callback
     */
    public function fixUsing(Closure $callback): PendingRun
    {
        return $this->pendingRun()->fixUsing($callback);
    }

    /**
     * Begin a run that invokes the given callback when applied fixes require
     * the diagnostics to be re-run.
     *
     * @param Closure(): void $callback
     */
    public function beforeRerun(Closure $callback): PendingRun
    {
        return $this->pendingRun()->beforeRerun($callback);
    }

    /**
     * Begin a new pending diagnostic run.
     */
    protected function pendingRun(): PendingRun
    {
        return new PendingRun($this, $this->defaultSelection());
    }

    /**
     * Get the diagnostic selection configured for the application.
     */
    protected function defaultSelection(): DiagnosticSelection
    {
        return DiagnosticSelection::make(
            only: array_filter(config()->array('doctor.only'), is_string(...)),
            except: array_filter(config()->array('doctor.except'), is_string(...)),
        );
    }

    /**
     * Run a single diagnostic.
     */
    public function check(Diagnostic $diagnostic): DiagnosticOutcome
    {
        try {
            $result = $diagnostic->check();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            $result = DiagnosticResult::error($e->getMessage())
                ->withContext('exception', $e::class);
        }

        return new DiagnosticOutcome($diagnostic, $result);
    }

    /**
     * Run the fix for a diagnostic outcome.
     */
    public function fix(DiagnosticOutcome $outcome, ?string $option = null): DiagnosticFixOutcome
    {
        $diagnostic = $outcome->diagnostic;

        if (! $diagnostic instanceof Fixable) {
            throw new LogicException(sprintf('Diagnostic [%s] does not implement [%s].', $diagnostic::class, Fixable::class));
        }

        if (! $outcome->fixable()) {
            throw new LogicException(sprintf('Diagnostic outcome [%s] is not fixable.', $outcome->result->code ?? $diagnostic::class));
        }

        $options = $outcome->result->fixOptions;

        if ($option === null && $options !== []) {
            throw new LogicException(sprintf('Diagnostic outcome [%s] requires a fix option.', $outcome->result->code ?? $diagnostic::class));
        }

        if ($option !== null && $options === []) {
            throw new LogicException(sprintf('Diagnostic outcome [%s] does not accept a fix option.', $outcome->result->code ?? $diagnostic::class));
        }

        if ($option !== null && ! array_key_exists($option, $options)) {
            throw new LogicException(sprintf('Fix option [%s] is not declared for diagnostic outcome [%s].', $option, $outcome->result->code ?? $diagnostic::class));
        }

        try {
            $result = $diagnostic->fix($outcome->result, $option);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            $result = FixResult::error(
                sprintf('Failed to fix %s: %s', $diagnostic->name, $e->getMessage()),
            )->withContext('exception', $e::class);
        }

        return new DiagnosticFixOutcome($diagnostic, $result);
    }

    /**
     * Get the selected diagnostics grouped by diagnostic group.
     *
     * @return array<string, list<Diagnostic>>
     */
    public function selectedByGroup(?DiagnosticSelection $selection = null): array
    {
        return collect($this->selected($selection))
            ->groupBy(static fn (Diagnostic $diagnostic): string => $diagnostic->group)
            ->map(static fn (Collection $diagnostics): array => array_values($diagnostics->all()))
            ->all();
    }

    /**
     * Get the selected diagnostic instances in registration order.
     *
     * @return list<Diagnostic>
     */
    protected function selected(?DiagnosticSelection $selection = null): array
    {
        $diagnostics = [];

        foreach ($this->diagnostics as $class) {
            $diagnostic = $this->container->makeTransient($class);

            if ($selection === null || $selection->matches($diagnostic)) {
                $diagnostics[] = $diagnostic;
            }
        }

        return $diagnostics;
    }
}
