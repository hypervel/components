<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Contracts;

use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;

interface Fixable
{
    /**
     * Fix the diagnostic.
     *
     * When the result declares fix options, the option is one of the declared
     * option values; otherwise it is null.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult;
}
