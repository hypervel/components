<?php

declare(strict_types=1);

namespace RepositoryOffsets;

use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository as ConfigRepository;
use stdClass;

function test(ConfigRepository $config, CacheRepository $cache): void
{
    $config->offsetGet('app.name');
    $config->offsetGet(new stdClass());
    $config->offsetSet(new stdClass(), 'value');

    $cache->offsetGet('key');
    $cache->offsetGet(new stdClass());
    $cache->offsetExists(1);
}
