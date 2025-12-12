<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests edge cases: null, zero, empty string, special characters, complex data.
 *
 * Most tests are mode-agnostic, but tag hash verification is any mode specific.
 */
final class EdgeCasesCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Edge Cases';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Null values
        $ctx->cache->put($ctx->prefixed('edge:null'), null, 60);
        $result->assert(
            $ctx->cache->has($ctx->prefixed('edge:null')) === false,
            'null values are not stored (Laravel behavior)'
        );

        // Zero values
        $ctx->cache->put($ctx->prefixed('edge:zero'), 0, 60);
        $result->assert(
            (int) $ctx->cache->get($ctx->prefixed('edge:zero')) === 0,
            'Zero values are stored and retrieved'
        );

        // Empty string
        $ctx->cache->put($ctx->prefixed('edge:empty'), '', 60);
        $result->assert(
            $ctx->cache->get($ctx->prefixed('edge:empty')) === '',
            'Empty strings are stored'
        );

        // Numeric tags
        $numericTags = [$ctx->prefixed('123'), $ctx->prefixed('string-tag')];
        $numericTagKey = $ctx->prefixed('edge:numeric-tags');
        $ctx->cache->tags($numericTags)->put($numericTagKey, 'value', 60);

        if ($ctx->isAnyMode()) {
            $result->assert(
                $ctx->redis->hexists($ctx->tagHashKey($ctx->prefixed('123')), $numericTagKey) === true,
                'Numeric tags are handled (cast to strings, any mode)'
            );
        } else {
            // For all mode, verify the key was stored using tagged get
            $result->assert(
                $ctx->cache->tags($numericTags)->get($numericTagKey) === 'value',
                'Numeric tags are handled (cast to strings, all mode)'
            );
        }

        // Special characters in keys
        $ctx->cache->put($ctx->prefixed('edge:special!@#$%'), 'special', 60);
        $result->assert(
            $ctx->cache->get($ctx->prefixed('edge:special!@#$%')) === 'special',
            'Special characters in keys are handled'
        );

        // Complex data structures
        $complex = [
            'nested' => [
                'array' => [1, 2, 3],
                'object' => (object) ['key' => 'value'],
            ],
            'boolean' => true,
            'float' => 3.14159,
        ];
        $complexTag = $ctx->prefixed('complex');
        $complexKey = $ctx->prefixed('edge:complex');
        $ctx->cache->tags([$complexTag])->put($complexKey, $complex, 60);

        if ($ctx->isAnyMode()) {
            $retrieved = $ctx->cache->get($complexKey);
        } else {
            $retrieved = $ctx->cache->tags([$complexTag])->get($complexKey);
        }
        $result->assert(
            is_array($retrieved) && $retrieved['nested']['array'][0] === 1,
            'Complex data structures are serialized and deserialized'
        );

        return $result;
    }
}
