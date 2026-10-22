<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;

class DatabaseDiagnostic extends Diagnostic
{
    public string $name = 'Database connects';

    public string $group = 'database';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::pass('The database diagnostic passed.');
    }
}
