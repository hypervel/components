<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Contracts;

use OpenTelemetry\SDK\Metrics\View\SelectionCriteriaInterface;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;

interface MetricView
{
    /**
     * Return the instruments selected by this view.
     */
    public function criteria(): SelectionCriteriaInterface;

    /**
     * Return the view applied to selected instruments.
     */
    public function template(): ViewTemplate;
}
