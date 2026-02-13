<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\Context\Context;
use Sentry\Event;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Throwable;

class HttpPoolTransport implements TransportInterface
{
    public function __construct(protected Pool $pool)
    {
    }

    public function send(Event $event): Result
    {
        /** @var HttpTransport $transport */
        $transport = $this->pool->get();

        Context::set('__sentry.transport', $transport);

        try {
            return $transport->send($event);
        } catch (Throwable) {
            $this->pool->release($transport);

            return new Result(ResultStatus::failed());
        }
    }

    public function close(?int $timeout = null): Result
    {
        if ($transport = Context::get('__sentry.transport')) {
            $this->pool->release($transport);
        }

        return new Result(ResultStatus::success());
    }
}
