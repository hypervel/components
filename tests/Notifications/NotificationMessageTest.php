<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Notifications\Messages\SimpleMessage as Message;
use Hypervel\Tests\TestCase;

class NotificationMessageTest extends TestCase
{
    public function testLevelCanBeRetrieved(): void
    {
        $message = new Message;
        $this->assertSame('info', $message->level);

        $message = new Message;
        $message->level('error');
        $this->assertSame('error', $message->level);
    }

    public function testMessageFormatsMultiLineText(): void
    {
        $message = new Message;
        $message->with('
            This is a
            single line of text.
        ');

        $this->assertSame('This is a single line of text.', $message->introLines[0]);

        $message = new Message;
        $message->with([
            'This is a',
            'single line of text.',
        ]);

        $this->assertSame('This is a single line of text.', $message->introLines[0]);
    }
}
