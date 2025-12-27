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

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        if ($ctx->isAnyMode()) {
            $this->testAnyMode($ctx, $result);
        } else {
            $this->testAllMode($ctx, $result);
        }

        return $result;
    }

    private function testAnyMode(DoctorContext $ctx, CheckResult $result): void
    {
        // Setup items with different tag combinations
        $ctx->cache->tags([$ctx->prefixed('color:red'), $ctx->prefixed('color:blue')])->put($ctx->prefixed('flush:purple'), 'purple', 60);
        $ctx->cache->tags([$ctx->prefixed('color:red'), $ctx->prefixed('color:yellow')])->put($ctx->prefixed('flush:orange'), 'orange', 60);
        $ctx->cache->tags([$ctx->prefixed('color:blue'), $ctx->prefixed('color:yellow')])->put($ctx->prefixed('flush:green'), 'green', 60);
        $ctx->cache->tags([$ctx->prefixed('color:red')])->put($ctx->prefixed('flush:red'), 'red only', 60);
        $ctx->cache->tags([$ctx->prefixed('color:blue')])->put($ctx->prefixed('flush:blue'), 'blue only', 60);

        // Flush one tag
        $ctx->cache->tags([$ctx->prefixed('color:red')])->flush();

        $result->assert(
            $ctx->cache->get($ctx->prefixed('flush:purple')) === null
            && $ctx->cache->get($ctx->prefixed('flush:orange')) === null
            && $ctx->cache->get($ctx->prefixed('flush:red')) === null
            && $ctx->cache->get($ctx->prefixed('flush:green')) === 'green'
            && $ctx->cache->get($ctx->prefixed('flush:blue')) === 'blue only',
            'Flushing one tag removes all items with that tag (any/OR behavior)'
        );

        // Flush multiple tags
        $ctx->cache->tags([$ctx->prefixed('color:blue'), $ctx->prefixed('color:yellow')])->flush();

        $result->assert(
            $ctx->cache->get($ctx->prefixed('flush:green')) === null
            && $ctx->cache->get($ctx->prefixed('flush:blue')) === null,
            'Flushing multiple tags removes items with ANY of those tags'
        );
    }

    private function testAllMode(DoctorContext $ctx, CheckResult $result): void
    {
        // Setup items with different tag combinations
        $redTag = $ctx->prefixed('color:red');
        $blueTag = $ctx->prefixed('color:blue');
        $yellowTag = $ctx->prefixed('color:yellow');

        $purpleTags = [$redTag, $blueTag];
        $orangeTags = [$redTag, $yellowTag];
        $greenTags = [$blueTag, $yellowTag];

        $ctx->cache->tags($purpleTags)->put($ctx->prefixed('flush:purple'), 'purple', 60);
        $ctx->cache->tags($orangeTags)->put($ctx->prefixed('flush:orange'), 'orange', 60);
        $ctx->cache->tags($greenTags)->put($ctx->prefixed('flush:green'), 'green', 60);
        $ctx->cache->tags([$redTag])->put($ctx->prefixed('flush:red'), 'red only', 60);
        $ctx->cache->tags([$blueTag])->put($ctx->prefixed('flush:blue'), 'blue only', 60);

        // Flush one tag - removes all items tracked in that tag's ZSET
        $ctx->cache->tags([$redTag])->flush();

        // Items with red tag should be gone (purple, orange, red)
        // Items without red tag should remain (green, blue)
        $purpleGone = $ctx->cache->tags($purpleTags)->get($ctx->prefixed('flush:purple')) === null;
        $orangeGone = $ctx->cache->tags($orangeTags)->get($ctx->prefixed('flush:orange')) === null;
        $redGone = $ctx->cache->tags([$redTag])->get($ctx->prefixed('flush:red')) === null;
        $greenExists = $ctx->cache->tags($greenTags)->get($ctx->prefixed('flush:green')) === 'green';
        $blueExists = $ctx->cache->tags([$blueTag])->get($ctx->prefixed('flush:blue')) === 'blue only';

        $result->assert(
            $purpleGone && $orangeGone && $redGone && $greenExists && $blueExists,
            'Flushing one tag removes all items tracked in that tag ZSET'
        );

        // Flush multiple tags - removes items tracked in ANY of those ZSETs
        $ctx->cache->tags([$blueTag, $yellowTag])->flush();

        $greenGone = $ctx->cache->tags($greenTags)->get($ctx->prefixed('flush:green')) === null;
        $blueGone = $ctx->cache->tags([$blueTag])->get($ctx->prefixed('flush:blue')) === null;

        $result->assert(
            $greenGone && $blueGone,
            'Flushing multiple tags removes items tracked in ANY of those ZSETs'
        );
    }
}
