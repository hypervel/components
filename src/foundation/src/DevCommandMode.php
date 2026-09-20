<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

enum DevCommandMode: string
{
    case TABS = 'tabs';
    case STREAM = 'stream';
    case INLINE = 'inline';
}
