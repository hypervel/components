<?php

declare(strict_types=1);

namespace Hypervel\Mail\Transport;

use Hyperf\Stringable\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class LogTransport implements TransportInterface
{
    /**
     * Create a new log transport instance.
     */
    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $string = Str::of($message->toString());

        if ($string->contains('Content-Type: multipart/')) {
            $boundary = $string
                ->after('boundary=')
                ->before("\r\n")
                ->prepend('--')
                ->append("\r\n")
                ->toString();

            $string = $string
                ->explode($boundary)
                ->map($this->decodeQuotedPrintableContent(...))
                ->implode($boundary);
        } elseif ($string->contains('Content-Transfer-Encoding: quoted-printable')) {
            $string = $this->decodeQuotedPrintableContent($string->toString());
        }

        $this->logger->debug((string) $string);

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    /**
     * Decode the given quoted printable content.
     */
    protected function decodeQuotedPrintableContent(string $part): string
    {
        if (! str_contains($part, 'Content-Transfer-Encoding: quoted-printable')) {
            return $part;
        }

        [$headers, $content] = explode("\r\n\r\n", $part, 2);

        return implode("\r\n\r\n", [
            $headers,
            quoted_printable_decode($content),
        ]);
    }

    /**
     * Get the logger for the LogTransport instance.
     */
    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'log';
    }
}
