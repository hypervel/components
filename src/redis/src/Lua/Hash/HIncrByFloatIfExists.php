<?php

declare(strict_types=1);

namespace Hypervel\Redis\Lua\Hash;

use Hypervel\Redis\Lua\Script;

class HIncrByFloatIfExists extends Script
{
    /**
     * Get the script content.
     */
    public function getScript(): string
    {
        return <<<'LUA'
    if(redis.call('type', KEYS[1]).ok == 'hash') then
        return redis.call('HINCRBYFLOAT', KEYS[1], ARGV[1], ARGV[2]);
    end
    return "";
LUA;
    }

    /**
     * Format the script result.
     */
    public function format(mixed $data): ?float
    {
        if (is_numeric($data)) {
            return (float) $data;
        }
        return null;
    }

    /**
     * Get the script key count.
     *
     * @param array<int, mixed> $arguments
     */
    protected function getKeyNumber(array $arguments): int
    {
        return 1;
    }
}
