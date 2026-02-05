<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests remember() and rememberForever() with tags.
 *
 * These operations work similarly in both tagging modes.
 */
final class TaggedRememberCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Tagged Remember Operations';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        $tag = $context->prefixed('remember');
        $rememberKey = $context->prefixed('tag:remember');
        $foreverKey = $context->prefixed('tag:forever');

        // Remember with tags
        $value = $context->cache->tags([$tag])->remember(
            $rememberKey,
            60,
            fn (): string => 'remembered-value'
        );

        if ($context->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $value === 'remembered-value' && $context->cache->get($rememberKey) === 'remembered-value', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
                'remember() with tags stores and returns value'
            );
        } else {
            // All mode: must use tagged get
            $result->assert(
                $value === 'remembered-value' && $context->cache->tags([$tag])->get($rememberKey) === 'remembered-value', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
                'remember() with tags stores and returns value'
            );
        }

        // RememberForever with tags
        $value = $context->cache->tags([$tag])->rememberForever(
            $foreverKey,
            fn (): string => 'forever-value'
        );

        if ($context->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $value === 'forever-value' && $context->cache->get($foreverKey) === 'forever-value', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
                'rememberForever() with tags stores and returns value'
            );
        } else {
            // All mode: must use tagged get
            $result->assert(
                $value === 'forever-value' && $context->cache->tags([$tag])->get($foreverKey) === 'forever-value', // @phpstan-ignore identical.alwaysTrue (diagnostic assertion)
                'rememberForever() with tags stores and returns value'
            );
        }

        return $result;
    }
}
