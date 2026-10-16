<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Providers\Tools\ProviderTool;

class ToolNameResolver
{
    /**
     * Resolve the tool's explicit name or class basename.
     */
    public static function resolve(Tool|ProviderTool $tool): string
    {
        return is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool);
    }
}
