<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle\Aspects;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Http\Client\Guzzle\CoroutineOwnership;
use Throwable;

class TransportOwnershipAspect extends AbstractAspect
{
    public array $classes = [
        CurlMultiHandler::class . '::__invoke',
        CurlMultiHandler::class . '::tick',
        CurlMultiHandler::class . '::execute',
        CurlMultiHandler::class . '::close',
    ];

    /**
     * Reserve a multi-handler before preparation and retain unfinished transfers.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        /** @var CurlMultiHandler $handler */
        $handler = $proceedingJoinPoint->getInstance();

        if ($proceedingJoinPoint->methodName !== '__invoke') {
            CoroutineOwnership::ensureTransportOwner($handler, $proceedingJoinPoint->methodName);

            return $proceedingJoinPoint->process();
        }

        CoroutineOwnership::reserve($handler);

        try {
            $result = $proceedingJoinPoint->process();
        } catch (Throwable $exception) {
            CoroutineOwnership::release($handler);

            throw $exception;
        }

        if ($result instanceof Promise && $result->getState() === PromiseInterface::PENDING) {
            CoroutineOwnership::track($result, $handler);
        } else {
            CoroutineOwnership::release($handler);
        }

        return $result;
    }
}
