<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

class FakeEventWithModel
{
    public $model;

    public function __construct($id)
    {
        $this->model = new FakeModel();
        $this->model->id = $id;
    }
}
