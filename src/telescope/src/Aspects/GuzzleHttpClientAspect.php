<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Aspects;

use GuzzleHttp\Client;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hypervel\Telescope\Watchers\HttpClientWatcher;

class GuzzleHttpClientAspect extends AbstractAspect
{
    public array $classes = [
        Client::class . '::transfer',
    ];

    public function __construct(
        protected HttpClientWatcher $watcher
    ) {
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        return $this->watcher
            ->recordRequest($proceedingJoinPoint);
    }
}
