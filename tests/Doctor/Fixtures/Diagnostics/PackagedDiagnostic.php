<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;

class PackagedDiagnostic extends Diagnostic
{
    public string $name = 'Testing diagnostic from a package';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::pass('The packaged diagnostic passed.');
    }

    /**
     * Get the diagnostic's package name.
     */
    public function package(): ?string
    {
        return 'vendor/package';
    }
}
