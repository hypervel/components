<?php

namespace FacadeFakeTypes;

use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Notification;
use Hypervel\Support\Facades\Queue;
use function PHPStan\Testing\assertType;

function queue(): void
{
    assertType('null', Queue::assertPushed('FooJob'));
    assertType('null', Queue::assertNothingPushed());
    assertType('Hypervel\Support\Collection', Queue::pushed('FooJob'));
    assertType('bool', Queue::hasPushed('FooJob'));
}

function others(object $notifiable): void
{
    assertType('null', Bus::assertDispatched('FooJob'));
    assertType('null', Mail::assertSent('FooMailable'));
    assertType('null', Notification::assertSentTo($notifiable, 'FooNotification'));
}
