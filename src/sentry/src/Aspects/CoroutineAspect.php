<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Aspects;

use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hypervel\Engine\Coroutine;
use Hypervel\Coroutine\Coroutine as HypervelCoroutine;
use Hypervel\Sentry\Switcher;
use Sentry\SentrySdk;
use Throwable;

class CoroutineAspect extends AbstractAspect
{
    public array $classes = [
        'Hypervel\Coroutine\Coroutine::create',
    ];

    protected array $keys = [
        SentrySdk::class,
        \Psr\Http\Message\ServerRequestInterface::class,
    ];

    public function __construct(protected Switcher $switcher)
    {
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        // If the coroutine aspect is disabled, we will not record the request.
        if (! $this->switcher->isEnabled('coroutine')) {
            return $proceedingJoinPoint->process();
        }
        $callable = $proceedingJoinPoint->arguments['keys']['callable'];
        $keys = $this->keys;
        $cid = Coroutine::id();

        $proceedingJoinPoint->arguments['keys']['callable'] = function () use ($callable, $cid, $keys) {
            $from = Coroutine::getContextFor($cid);
            $current = Coroutine::getContextFor();

            foreach ($keys as $key) {
                if (isset($from[$key])) {
                    $current[$key] = $from[$key];
                }
            }

            try {
                $callable();
            } catch (Throwable $throwable) {
                HypervelCoroutine::enableReportException(false);
                SentrySdk::getCurrentHub()->captureException($throwable);
                throw $throwable;
            }
        };

        return $proceedingJoinPoint->process();
    }
}
