<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Aspects;

class CoroutineAspect extends \FriendsOfHyperf\Sentry\Aspect\CoroutineAspect
{
    public array $classes = [
        'Hypervel\Coroutine\Coroutine::create',
    ];
}
