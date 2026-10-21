<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

enum AutoLazyReplayMode: string
{
    case Normal = 'Normal';
    case Hook = 'Hook';
}
