<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;

class NoticeDiagnostic extends Diagnostic
{
    public string $name = 'Testing diagnostic notices';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::notice('The diagnostic noticed.')
            ->suggest('This notice fixture simulates troubleshooting context.');
    }
}
