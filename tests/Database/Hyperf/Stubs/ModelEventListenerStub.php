<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hypervel\Database\Eloquent\Events\Event;

#[Listener]
class ModelEventListenerStub implements ListenerInterface
{
    public function listen(): array
    {
        return [
            Event::class,
        ];
    }

    /**
     * @param Event $event
     */
    public function process(object $event): void
    {
        $event->handle();
    }
}
