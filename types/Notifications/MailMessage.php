<?php

declare(strict_types=1);

namespace Hypervel\Types\Notifications;

use Hypervel\Notifications\Messages\MailMessage;

use function PHPStan\Testing\assertType;

function testMailMessageProperties(MailMessage $message): void
{
    assertType('array{}|array{0: array|string, 1?: string|null, ...}', $message->from);
    assertType('array<array{0: array|string, 1?: string|null, ...}>', $message->replyTo);
    assertType('array<array{0: array|string, 1?: string|null, ...}>', $message->cc);
    assertType('array<array{0: array|string, 1?: string|null, ...}>', $message->bcc);
    assertType('array<array{file: Hypervel\Contracts\Mail\Attachable|Hypervel\Mail\Attachment|string, options: array, ...}>', $message->attachments);
    assertType('array<array{data: resource|string, name: string, options: array, ...}>', $message->rawAttachments);
}
