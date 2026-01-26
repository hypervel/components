<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests flush behavior semantics.
 *
 * Any mode: Any tag flushes item (OR logic).
 * All mode: All tags required to flush (AND logic).
 */
final class FlushBehaviorCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Flush Behavior Semantics';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        if ($context->isAnyMode()) {
            $this->testAnyMode($context, $result);
        } else {
            $this->testAllMode($context, $result);
        }

        return $result;
    }

    private function testAnyMode(DoctorContext $context, CheckResult $result): void
    {
        // Setup items with different tag combinations
        $context->cache->tags([$context->prefixed('color:red'), $context->prefixed('color:blue')])->put($context->prefixed('flush:purple'), 'purple', 60);
        $context->cache->tags([$context->prefixed('color:red'), $context->prefixed('color:yellow')])->put($context->prefixed('flush:orange'), 'orange', 60);
        $context->cache->tags([$context->prefixed('color:blue'), $context->prefixed('color:yellow')])->put($context->prefixed('flush:green'), 'green', 60);
        $context->cache->tags([$context->prefixed('color:red')])->put($context->prefixed('flush:red'), 'red only', 60);
        $context->cache->tags([$context->prefixed('color:blue')])->put($context->prefixed('flush:blue'), 'blue only', 60);

        // Flush one tag
        $context->cache->tags([$context->prefixed('color:red')])->flush();

        $result->assert(
            $context->cache->get($context->prefixed('flush:purple')) === null
            && $context->cache->get($context->prefixed('flush:orange')) === null
            && $context->cache->get($context->prefixed('flush:red')) === null
            && $context->cache->get($context->prefixed('flush:green')) === 'green'
            && $context->cache->get($context->prefixed('flush:blue')) === 'blue only',
            'Flushing one tag removes all items with that tag (any/OR behavior)'
        );

        // Flush multiple tags
        $context->cache->tags([$context->prefixed('color:blue'), $context->prefixed('color:yellow')])->flush();

        $result->assert(
            $context->cache->get($context->prefixed('flush:green')) === null
            && $context->cache->get($context->prefixed('flush:blue')) === null,
            'Flushing multiple tags removes items with ANY of those tags'
        );
    }

    private function testAllMode(DoctorContext $context, CheckResult $result): void
    {
        // Setup items with different tag combinations
        $redTag = $context->prefixed('color:red');
        $blueTag = $context->prefixed('color:blue');
        $yellowTag = $context->prefixed('color:yellow');

        $purpleTags = [$redTag, $blueTag];
        $orangeTags = [$redTag, $yellowTag];
        $greenTags = [$blueTag, $yellowTag];

        $context->cache->tags($purpleTags)->put($context->prefixed('flush:purple'), 'purple', 60);
        $context->cache->tags($orangeTags)->put($context->prefixed('flush:orange'), 'orange', 60);
        $context->cache->tags($greenTags)->put($context->prefixed('flush:green'), 'green', 60);
        $context->cache->tags([$redTag])->put($context->prefixed('flush:red'), 'red only', 60);
        $context->cache->tags([$blueTag])->put($context->prefixed('flush:blue'), 'blue only', 60);

        // Flush one tag - removes all items tracked in that tag's ZSET
        $context->cache->tags([$redTag])->flush();

        // Items with red tag should be gone (purple, orange, red)
        // Items without red tag should remain (green, blue)
        $purpleGone = $context->cache->tags($purpleTags)->get($context->prefixed('flush:purple')) === null;
        $orangeGone = $context->cache->tags($orangeTags)->get($context->prefixed('flush:orange')) === null;
        $redGone = $context->cache->tags([$redTag])->get($context->prefixed('flush:red')) === null;
        $greenExists = $context->cache->tags($greenTags)->get($context->prefixed('flush:green')) === 'green';
        $blueExists = $context->cache->tags([$blueTag])->get($context->prefixed('flush:blue')) === 'blue only';

        $result->assert(
            $purpleGone && $orangeGone && $redGone && $greenExists && $blueExists,
            'Flushing one tag removes all items tracked in that tag ZSET'
        );

        // Flush multiple tags - removes items tracked in ANY of those ZSETs
        $context->cache->tags([$blueTag, $yellowTag])->flush();

        $greenGone = $context->cache->tags($greenTags)->get($context->prefixed('flush:green')) === null;
        $blueGone = $context->cache->tags([$blueTag])->get($context->prefixed('flush:blue')) === null;

        $result->assert(
            $greenGone && $blueGone,
            'Flushing multiple tags removes items tracked in ANY of those ZSETs'
        );
    }
}
