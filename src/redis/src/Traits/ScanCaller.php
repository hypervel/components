<?php

declare(strict_types=1);

namespace Hypervel\Redis\Traits;

trait ScanCaller
{
    /**
     * Scan keys.
     */
    public function scan(&$cursor, ...$arguments)
    {
        return $this->__call('scan', array_merge([&$cursor], $arguments));
    }

    /**
     * Scan hash fields.
     */
    public function hScan($key, &$cursor, ...$arguments)
    {
        return $this->__call('hScan', array_merge([$key, &$cursor], $arguments));
    }

    /**
     * Scan sorted set members.
     */
    public function zScan($key, &$cursor, ...$arguments)
    {
        return $this->__call('zScan', array_merge([$key, &$cursor], $arguments));
    }

    /**
     * Scan set members.
     */
    public function sScan($key, &$cursor, ...$arguments)
    {
        return $this->__call('sScan', array_merge([$key, &$cursor], $arguments));
    }
}
