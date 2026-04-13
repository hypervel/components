<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Aspects;

use GuzzleHttp\Client;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;

class GuzzleHttpClientAspect extends AbstractAspect
{
    public array $classes = [
        Client::class . '::transfer',
    ];

    public function __construct(
        protected ClientRequestWatcher $watcher
    ) {
    }

    /**
     * Delegate to the client request watcher for recording.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $this->watcher->recordRequest($proceedingJoinPoint);
    }
}
