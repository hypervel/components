<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Stubs;

use Redis;

class NativeRedisStub extends Redis
{
    public array $calls = [];

    public array $execResult = [];

    public mixed $getResult = null;

    public function pipeline(): Redis|bool
    {
        $this->calls[] = ['pipeline'];

        return $this;
    }

    public function multi(int $value = Redis::MULTI): Redis|bool
    {
        $this->calls[] = ['multi', $value];

        return $this;
    }

    public function set(string $key, mixed $value, mixed $options = null): Redis|string|bool
    {
        $this->calls[] = ['set', $key, $value, $options];

        return true;
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->calls[] = ['eval', $script, $args, $num_keys];

        return $args[0] ?? null;
    }

    public function exec(): Redis|array|false
    {
        $this->calls[] = ['exec'];

        return $this->execResult;
    }

    public function discard(): Redis|bool
    {
        $this->calls[] = ['discard'];

        return true;
    }

    public function get(string $key): mixed
    {
        $this->calls[] = ['get', $key];

        return $this->getResult;
    }
}
