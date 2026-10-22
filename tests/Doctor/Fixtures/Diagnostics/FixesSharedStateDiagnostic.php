<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;

class FixesSharedStateDiagnostic extends Diagnostic implements Fixable
{
    public string $name = 'Testing diagnostic fixes shared state';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (config('doctor-testing.shared-state-fixed') === true) {
            return DiagnosticResult::pass('The shared state is fixed.');
        }

        return DiagnosticResult::fail('The shared state needs fixing.')
            ->fixable()
            ->suggest('Apply the shared state fix.')
            ->confirmUsing('Fix the shared state?');
    }

    /**
     * Apply the diagnostic fix.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        config(['doctor-testing.shared-state-fixed' => true]);

        return FixResult::pass('The shared state fix ran.');
    }
}
