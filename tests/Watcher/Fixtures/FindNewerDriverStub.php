<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Driver\FindNewerDriver;

class FindNewerDriverStub extends FindNewerDriver
{
    public function watch(Channel $channel): void
    {
        foreach ($this->scan() as $file) {
            $channel->push($file);
        }
    }

    protected function scan(): array
    {
        return ['.env'];
    }
}
