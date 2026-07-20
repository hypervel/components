<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

interface PreparesForDispatch
{
    /**
     * Run preparation logic before dispatch. Return false to abort.
     *
     * @return bool|void
     */
    public function prepareForDispatch();
}
