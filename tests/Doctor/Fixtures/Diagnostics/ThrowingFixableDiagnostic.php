<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use RuntimeException;

class ThrowingFixableDiagnostic extends Diagnostic implements Fixable
{
    public string $name = 'Testing fixable diagnostic throws';

    public string $group = 'testing';

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        throw new RuntimeException('The fixable diagnostic exploded.');
    }

    /**
     * Apply the diagnostic fix.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        return FixResult::pass('The diagnostic was fixed.');
    }
}
