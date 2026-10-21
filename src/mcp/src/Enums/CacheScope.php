<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Enums;

enum CacheScope: string
{
    case Public = 'public';
    case Private = 'private';
}
