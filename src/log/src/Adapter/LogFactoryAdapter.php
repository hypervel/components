<?php

declare(strict_types=1);

namespace Hypervel\Log\Adapter;

use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class LogFactoryAdapter extends LoggerFactory
{
    // name and group parameters are not applicable here
    public function make($name = 'hyperf', $group = 'default'): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class)->channel();
    }

    // name and group parameters are not applicable here
    public function get($name = 'hyperf', $group = 'default'): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class)->channel();
    }
}
