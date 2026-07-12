<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Driver\FswatchDriver;

class FswatchDriverStub extends FswatchDriver
{
    public function watch(Channel $channel): void
    {
        $channel->push('.env');
        $this->watchAtInterval(60, static function (): void {
        });
    }
}
