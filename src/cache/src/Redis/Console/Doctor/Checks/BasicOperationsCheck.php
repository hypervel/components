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

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        // Put and get
        $context->cache->put($context->prefixed('basic:key1'), 'value1', 60);
        $result->assert(
            $context->cache->get($context->prefixed('basic:key1')) === 'value1',
            'put() and get() string value'
        );

        // Has
        $result->assert(
            $context->cache->has($context->prefixed('basic:key1')) === true,
            'has() returns true for existing key'
        );

        // Missing
        $result->assert(
            $context->cache->missing($context->prefixed('basic:nonexistent')) === true,
            'missing() returns true for non-existent key'
        );

        // Forget
        $context->cache->forget($context->prefixed('basic:key1'));
        $result->assert(
            $context->cache->get($context->prefixed('basic:key1')) === null,
            'forget() removes key'
        );

        // Pull
        $context->cache->put($context->prefixed('basic:pull'), 'pulled', 60);
        $value = $context->cache->pull($context->prefixed('basic:pull'));
        $result->assert(
            $value === 'pulled' && $context->cache->get($context->prefixed('basic:pull')) === null,
            'pull() retrieves and removes key'
        );

        // Remember
        $value = $context->cache->remember($context->prefixed('basic:remember'), 60, fn (): string => 'remembered');
        $result->assert(
            $value === 'remembered' && $context->cache->get($context->prefixed('basic:remember')) === 'remembered', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
            'remember() stores and returns closure result'
        );

        // RememberForever
        $value = $context->cache->rememberForever($context->prefixed('basic:forever'), fn (): string => 'permanent');
        $result->assert(
            $value === 'permanent', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
            'rememberForever() stores without expiration'
        );

        return $result;
    }
}
