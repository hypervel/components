<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Facades;

use Closure;
use Hypervel\Doctor\Doctor as DoctorManager;
use Hypervel\Support\Facades\Facade;

/**
 * @method static \Hypervel\Doctor\PendingRun bail(bool $bail = true)
 * @method static \Hypervel\Doctor\PendingRun beforeRerun(Closure $callback)
 * @method static \Hypervel\Doctor\Results\DiagnosticOutcome check(\Hypervel\Doctor\Diagnostic $diagnostic)
 * @method static \Hypervel\Doctor\Doctor diagnostic(string $diagnostic)
 * @method static \Hypervel\Doctor\Doctor diagnostics(iterable<string> $diagnostics)
 * @method static \Hypervel\Doctor\PendingRun except(string ...$selectors)
 * @method static \Hypervel\Doctor\Results\DiagnosticFixOutcome fix(\Hypervel\Doctor\Results\DiagnosticOutcome $outcome, string|null $option = null)
 * @method static \Hypervel\Doctor\PendingRun fixUsing(Closure $callback)
 * @method static bool hasDiagnostics()
 * @method static \Hypervel\Doctor\PendingRun observeUsing(Closure $callback)
 * @method static \Hypervel\Doctor\PendingRun only(string ...$selectors)
 * @method static array<int, string> registered()
 * @method static \Hypervel\Doctor\Results\DiagnosticReport run()
 * @method static array<string, array<int, \Hypervel\Doctor\Diagnostic>> selectedByGroup(\Hypervel\Doctor\DiagnosticSelection|null $selection = null)
 *
 * @see \Hypervel\Doctor\Doctor
 */
class Doctor extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return DoctorManager::class;
    }
}
