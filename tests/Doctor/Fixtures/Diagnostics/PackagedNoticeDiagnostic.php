<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;

class PackagedNoticeDiagnostic extends Diagnostic
{
    public string $name = 'Testing packaged diagnostic notices';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return DiagnosticResult::notice('The packaged diagnostic noticed.')
            ->suggest('This packaged notice fixture simulates troubleshooting context.');
    }

    /**
     * Get the diagnostic's package name.
     */
    public function package(): ?string
    {
        return 'vendor/package';
    }
}
