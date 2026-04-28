<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

enum WatchPathType
{
    case Directory;
    case File;
}
