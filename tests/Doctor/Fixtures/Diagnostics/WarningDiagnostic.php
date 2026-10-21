<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;

class WarningDiagnostic extends Diagnostic
{
    public string $name = 'Testing diagnostic warns';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::warn('The diagnostic warned.')
            ->withDetails('This warning fixture simulates a non-fixable issue.')
            ->suggest('Re-run this diagnostic after addressing the warning.');
    }
}
