<?php

declare(strict_types=1);

namespace Hypervel\Redis\Traits;

trait ScanCaller
{
    /**
     * Scan keys.
     * @param mixed $cursor
     */
    public function scan(&$cursor, ...$arguments)
    {
        return $this->__call('scan', array_merge([&$cursor], $arguments));
    }

    /**
     * Scan hash fields.
     * @param mixed $key
     * @param mixed $cursor
     */
    public function hScan($key, &$cursor, ...$arguments)
    {
        return $this->__call('hScan', array_merge([$key, &$cursor], $arguments));
    }

    /**
     * Scan sorted set members.
     * @param mixed $key
     * @param mixed $cursor
     */
    public function zScan($key, &$cursor, ...$arguments)
    {
        return $this->__call('zScan', array_merge([$key, &$cursor], $arguments));
    }

    /**
     * Scan set members.
     * @param mixed $key
     * @param mixed $cursor
     */
    public function sScan($key, &$cursor, ...$arguments)
    {
        return $this->__call('sScan', array_merge([$key, &$cursor], $arguments));
    }
}
