<?php

declare(strict_types=1);

namespace Hypervel\Redis\Lua\Hash;

use Hypervel\Redis\Lua\Script;

class HGetAllMultiple extends Script
{
    /**
     * Get the script content.
     */
    public function getScript(): string
    {
        return <<<'LUA'
    local values = {}; 
    for i,v in ipairs(KEYS) do 
        if(redis.call('type',v).ok == 'hash') then
            values[#values+1] = redis.call('hgetall',v);
        end
    end
    return values;
LUA;
    }

    /**
     * Format the script result.
     */
    public function format(mixed $data): array
    {
        $result = [];
        foreach ($data ?? [] as $item) {
            if (! empty($item) && is_array($item)) {
                $temp = [];
                $count = count($item);
                for ($i = 0; $i < $count; ++$i) {
                    $temp[$item[$i]] = $item[++$i];
                }

                $result[] = $temp;
            }
        }

        return $result;
    }
}
