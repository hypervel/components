<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

use Hypervel\Event\Contracts\Dispatcher;
use Hypervel\Horizon\Contracts\Silenced;

class FakeListenerSilenced implements Silenced
{
    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    /**
     * @var FakeEventWithModel
     */
    protected $fakeModel;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function handle(FakeEventWithModel $fakeEventWithModel): void
    {
        $this->fakeModel = $fakeEventWithModel->model;
    }
}
