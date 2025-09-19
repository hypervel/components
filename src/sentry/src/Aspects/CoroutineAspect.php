<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Aspects;

use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Engine\Coroutine;
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

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
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
                SentrySdk::getCurrentHub()->captureException($throwable);
                throw $throwable;
            }
        };

        return $proceedingJoinPoint->process();
    }
}
