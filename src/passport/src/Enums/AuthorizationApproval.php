<?php

declare(strict_types=1);

namespace Hypervel\Passport\Enums;

enum AuthorizationApproval
{
    case Prompt;
    case Remembered;
    case Policy;
}
