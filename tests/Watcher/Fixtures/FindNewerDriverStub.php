<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Driver\FindNewerDriver;

class FindNewerDriverStub extends FindNewerDriver
{
    public function watch(Channel $channel): void
    {
        [$changedFiles] = $this->scan();

        foreach ($changedFiles as $file) {
            $channel->push($file);
        }

        $this->watchAtInterval(60, static function (): void {
        });
    }

    protected function scan(): array
    {
        return [['.env'], null];
    }
}
