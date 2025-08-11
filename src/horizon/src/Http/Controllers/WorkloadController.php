<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\WorkloadRepository;

class WorkloadController extends Controller
{
    /**
     * Get the current queue workload for the application.
     */
    public function index(WorkloadRepository $workload): array
    {
        return collect($workload->get())->sortBy('name')->values()->toArray();
    }
}
