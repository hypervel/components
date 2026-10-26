<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;

class LocalOnlyFixableDiagnostic extends Diagnostic implements Fixable
{
    public string $name = 'Testing diagnostic is fixable locally';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::fail('The local diagnostic failed.')
            ->fixable(EnvironmentMode::Local)
            ->confirmUsing('Fix the local testing diagnostic?')
            ->suggest('Apply the local testing diagnostic fix.');
    }

    /**
     * Apply the diagnostic fix.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        return FixResult::pass('The local diagnostic was fixed.');
    }
}
