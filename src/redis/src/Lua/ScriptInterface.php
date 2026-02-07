<?php

declare(strict_types=1);

namespace Hypervel\Redis\Lua;

interface ScriptInterface
{
    /**
     * Get the script content.
     */
    public function getScript(): string;

    /**
     * Format the script result.
     */
    public function format(mixed $data): mixed;

    /**
     * Evaluate the script.
     *
     * @param array<int, mixed> $arguments
     */
    public function eval(array $arguments = [], bool $sha = true): mixed;
}
