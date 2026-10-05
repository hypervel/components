<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\Validation\RequiredIf;
use Hypervel\Data\Data;

class DataWithReferenceFieldValidationAttribute extends Data
{
    public bool $check_string;

    #[RequiredIf('check_string', true)]
    public string $string;
}
