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
    /**
     * Get the human-readable name of this check.
     */
    public function name(): string
    {
        return 'Sequential Rapid Operations';
    }

    /**
     * Run the check and return results.
     */
    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult;

        // Rapid writes to same key
        $rapidTag = $context->prefixed('rapid');
        $rapidKey = $context->prefixed('concurrent:key');
        for ($writeIndex = 0; $writeIndex < 10; ++$writeIndex) {
            $context->cache->tags([$rapidTag])->put($rapidKey, "value{$writeIndex}", 60);
        }

        if ($context->isAnyMode()) {
            $rapidValue = $context->cache->get($rapidKey);
        } else {
            $rapidValue = $context->cache->tags([$rapidTag])->get($rapidKey);
        }
        $result->assert(
            $rapidValue === 'value9',
            'Last write wins in rapid succession'
        );

        // Multiple increments
        $context->cache->put($context->prefixed('concurrent:counter'), 0, 60);

        for ($incrementIndex = 0; $incrementIndex < 50; ++$incrementIndex) {
            $context->cache->increment($context->prefixed('concurrent:counter'));
        }

        $result->assert(
            $context->cache->get($context->prefixed('concurrent:counter')) === '50',
            'Multiple increments all applied correctly'
        );

        // Race condition: add operations
        $context->cache->forget($context->prefixed('concurrent:add'));
        $results = [];

        for ($addIndex = 0; $addIndex < 5; ++$addIndex) {
            $results[] = $context->cache->add($context->prefixed('concurrent:add'), "value{$addIndex}", 60);
        }

        $result->assert(
            $results[0] === true && array_sum($results) === 1,
            'add() is atomic (only first succeeds)'
        );

        // Overlapping tag operations
        $overlapTags = [$context->prefixed('overlap1'), $context->prefixed('overlap2')];
        $overlapKey = $context->prefixed('concurrent:overlap');
        $context->cache->tags($overlapTags)->put($overlapKey, 'value', 60);
        $context->cache->tags([$context->prefixed('overlap1')])->flush();

        if ($context->isAnyMode()) {
            $overlapValue = $context->cache->get($overlapKey);
        } else {
            $overlapValue = $context->cache->tags($overlapTags)->get($overlapKey);
        }
        $result->assert(
            $overlapValue === null,
            'Partial flush removes item correctly'
        );

        return $result;
    }
}
