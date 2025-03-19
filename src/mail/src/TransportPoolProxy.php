<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\ObjectPool\PoolProxy;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class TransportPoolProxy extends PoolProxy implements TransportInterface
{
    /**
     * @throws TransportExceptionInterface
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    public function __toString(): string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }
}
