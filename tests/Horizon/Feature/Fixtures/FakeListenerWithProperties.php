<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

use Hypervel\Contracts\Event\Dispatcher;

class FakeListenerWithProperties
{
    protected Dispatcher $dispatcher;

    protected FakeEventWithModel $fakeModel;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function handle(FakeEventWithModel $fakeEventWithModel): void
    {
        $this->fakeModel = $fakeEventWithModel->model;
    }
}
