<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;

class PassingDiagnostic extends Diagnostic
{
    public string $name = 'Testing diagnostic passes';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::pass('The diagnostic passed.');
    }
}
