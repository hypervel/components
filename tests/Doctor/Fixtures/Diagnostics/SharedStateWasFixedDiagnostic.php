<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;

class SharedStateWasFixedDiagnostic extends Diagnostic
{
    public string $name = 'Testing shared state was fixed';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (config()->boolean('doctor-testing.shared-state-fixed', false)) {
            return DiagnosticResult::pass('The shared state dependent diagnostic passed.');
        }

        return DiagnosticResult::fail('The shared state is still broken.');
    }
}
