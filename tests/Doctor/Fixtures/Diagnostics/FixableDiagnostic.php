<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;

class FixableDiagnostic extends Diagnostic implements Fixable
{
    public string $name = 'Testing diagnostic is fixable';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::fail('The diagnostic failed.')
            ->fixable()
            ->confirmUsing('Fix the testing diagnostic?')
            ->suggest('Apply the testing diagnostic fix.');
    }

    /**
     * Apply the diagnostic fix.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        return FixResult::pass('The diagnostic was fixed.');
    }
}
