<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use RuntimeException;

class ThrowingDiagnostic extends Diagnostic
{
    public string $name = 'Testing diagnostic throws';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        throw new RuntimeException('The diagnostic exploded.');
    }
}
