<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify\Fixtures;

use Hypervel\Fortify\InteractsWithTwoFactorState;
use Hypervel\Foundation\Http\FormRequest;

class FormRequestInteractsWithTwoFactorState extends FormRequest
{
    use InteractsWithTwoFactorState;
}
