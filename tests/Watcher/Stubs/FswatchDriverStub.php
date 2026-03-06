<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Stubs;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Driver\FswatchDriver;

class FswatchDriverStub extends FswatchDriver
{
    public function watch(Channel $channel): void
    {
        $seconds = $this->option->getScanIntervalSeconds();
        $this->timerId = $this->timer->tick($seconds, function () use ($channel) {
            $channel->push('.env');
        });
    }
}
