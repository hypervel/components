<?php

declare(strict_types=1);

use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;

use function PHPStan\Testing\assertType;

/** @var RedisConnection $connection */
$connection = resolve(RedisConnection::class);

assertType("'foo'", $connection->withoutSerializationOrCompression(fn () => 'foo'));

/** @var RedisProxy $proxy */
$proxy = resolve(RedisProxy::class);

assertType("'foo'", $proxy->withoutSerializationOrCompression(fn () => 'foo'));
