<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;

class OptionFixableDiagnostic extends Diagnostic implements Fixable
{
    public string $name = 'Testing diagnostic offers fix options';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (config('doctor-testing.option-fixed') !== null) {
            return DiagnosticResult::pass('The option diagnostic is fixed.');
        }

        return DiagnosticResult::fail('The option diagnostic failed.')
            ->fixable()
            ->fixOptions(['a' => 'Option A', 'b' => 'Option B'])
            ->confirmUsing('Which option should fix the diagnostic?')
            ->suggest('Apply one of the diagnostic fix options.');
    }

    /**
     * Apply the diagnostic fix.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        config(['doctor-testing.option-fixed' => $option]);

        return FixResult::pass(sprintf('The option diagnostic was fixed with [%s].', $option));
    }
}
