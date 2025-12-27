<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests rapid sequential operations.
 *
 * This check is mode-agnostic.
 */
final class SequentialOperationsCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Sequential Rapid Operations';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Rapid writes to same key
        $rapidTag = $ctx->prefixed('rapid');
        $rapidKey = $ctx->prefixed('concurrent:key');
        for ($i = 0; $i < 10; ++$i) {
            $ctx->cache->tags([$rapidTag])->put($rapidKey, "value{$i}", 60);
        }

        if ($ctx->isAnyMode()) {
            $rapidValue = $ctx->cache->get($rapidKey);
        } else {
            $rapidValue = $ctx->cache->tags([$rapidTag])->get($rapidKey);
        }
        $result->assert(
            $rapidValue === 'value9',
            'Last write wins in rapid succession'
        );

        // Multiple increments
        $ctx->cache->put($ctx->prefixed('concurrent:counter'), 0, 60);

        for ($i = 0; $i < 50; ++$i) {
            $ctx->cache->increment($ctx->prefixed('concurrent:counter'));
        }

        $result->assert(
            $ctx->cache->get($ctx->prefixed('concurrent:counter')) === '50',
            'Multiple increments all applied correctly'
        );

        // Race condition: add operations
        $ctx->cache->forget($ctx->prefixed('concurrent:add'));
        $results = [];

        for ($i = 0; $i < 5; ++$i) {
            $results[] = $ctx->cache->add($ctx->prefixed('concurrent:add'), "value{$i}", 60);
        }

        $result->assert(
            $results[0] === true && array_sum($results) === 1,
            'add() is atomic (only first succeeds)'
        );

        // Overlapping tag operations
        $overlapTags = [$ctx->prefixed('overlap1'), $ctx->prefixed('overlap2')];
        $overlapKey = $ctx->prefixed('concurrent:overlap');
        $ctx->cache->tags($overlapTags)->put($overlapKey, 'value', 60);
        $ctx->cache->tags([$ctx->prefixed('overlap1')])->flush();

        if ($ctx->isAnyMode()) {
            $overlapValue = $ctx->cache->get($overlapKey);
        } else {
            $overlapValue = $ctx->cache->tags($overlapTags)->get($overlapKey);
        }
        $result->assert(
            $overlapValue === null,
            'Partial flush removes item correctly'
        );

        return $result;
    }
}
