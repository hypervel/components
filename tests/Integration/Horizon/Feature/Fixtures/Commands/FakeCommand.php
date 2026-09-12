<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Commands;

use Hypervel\Horizon\Supervisor;

class FakeCommand
{
    public int $processCount = 0;

    public ?Supervisor $supervisor = null;

    public ?array $options = null;

    /**
     * Record the supervisor and command options.
     */
    public function process(Supervisor $supervisor, array $options): void
    {
        ++$this->processCount;
        $this->supervisor = $supervisor;
        $this->options = $options;
    }
}
