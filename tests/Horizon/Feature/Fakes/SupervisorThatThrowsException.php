<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fakes;

use Exception;
use Hypervel\Horizon\Supervisor;

class SupervisorThatThrowsException extends Supervisor
{
    /**
     * Persist information about this supervisor instance.
     *
     * @throws Exception
     */
    public function persist(): void
    {
        throw new Exception();
    }
}
