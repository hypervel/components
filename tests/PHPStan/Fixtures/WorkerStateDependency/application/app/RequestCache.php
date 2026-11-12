<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use Hypervel\Http\Request;

class RequestCache
{
    protected ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}
