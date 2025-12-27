<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests basic cache operations: put, get, has, missing, forget, pull, remember.
 *
 * These operations are mode-agnostic (work the same for both tagging modes).
 */
final class BasicOperationsCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Basic Cache Operations';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Put and get
        $ctx->cache->put($ctx->prefixed('basic:key1'), 'value1', 60);
        $result->assert(
            $ctx->cache->get($ctx->prefixed('basic:key1')) === 'value1',
            'put() and get() string value'
        );

        // Has
        $result->assert(
            $ctx->cache->has($ctx->prefixed('basic:key1')) === true,
            'has() returns true for existing key'
        );

        // Missing
        $result->assert(
            $ctx->cache->missing($ctx->prefixed('basic:nonexistent')) === true,
            'missing() returns true for non-existent key'
        );

        // Forget
        $ctx->cache->forget($ctx->prefixed('basic:key1'));
        $result->assert(
            $ctx->cache->get($ctx->prefixed('basic:key1')) === null,
            'forget() removes key'
        );

        // Pull
        $ctx->cache->put($ctx->prefixed('basic:pull'), 'pulled', 60);
        $value = $ctx->cache->pull($ctx->prefixed('basic:pull'));
        $result->assert(
            $value === 'pulled' && $ctx->cache->get($ctx->prefixed('basic:pull')) === null,
            'pull() retrieves and removes key'
        );

        // Remember
        $value = $ctx->cache->remember($ctx->prefixed('basic:remember'), 60, fn (): string => 'remembered');
        $result->assert(
            $value === 'remembered' && $ctx->cache->get($ctx->prefixed('basic:remember')) === 'remembered', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
            'remember() stores and returns closure result'
        );

        // RememberForever
        $value = $ctx->cache->rememberForever($ctx->prefixed('basic:forever'), fn (): string => 'permanent');
        $result->assert(
            $value === 'permanent', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
            'rememberForever() stores without expiration'
        );

        return $result;
    }
}
