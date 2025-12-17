<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Support\MessageBag as HyperfMessageBag;
use Hypervel\Support\Contracts\MessageBag as ContractsMessageBag;

class MessageBag extends HyperfMessageBag implements ContractsMessageBag
{
}
