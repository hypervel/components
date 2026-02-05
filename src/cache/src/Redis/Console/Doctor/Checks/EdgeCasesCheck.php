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

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        // Null values
        $context->cache->put($context->prefixed('edge:null'), null, 60);
        $result->assert(
            $context->cache->has($context->prefixed('edge:null')) === false,
            'null values are not stored (Laravel behavior)'
        );

        // Zero values
        $context->cache->put($context->prefixed('edge:zero'), 0, 60);
        $result->assert(
            (int) $context->cache->get($context->prefixed('edge:zero')) === 0,
            'Zero values are stored and retrieved'
        );

        // Empty string
        $context->cache->put($context->prefixed('edge:empty'), '', 60);
        $result->assert(
            $context->cache->get($context->prefixed('edge:empty')) === '',
            'Empty strings are stored'
        );

        // Numeric tags
        $numericTags = [$context->prefixed('123'), $context->prefixed('string-tag')];
        $numericTagKey = $context->prefixed('edge:numeric-tags');
        $context->cache->tags($numericTags)->put($numericTagKey, 'value', 60);

        if ($context->isAnyMode()) {
            $result->assert(
                $context->redis->hExists($context->tagHashKey($context->prefixed('123')), $numericTagKey) === true,
                'Numeric tags are handled (cast to strings, any mode)'
            );
        } else {
            // For all mode, verify the key was stored using tagged get
            $result->assert(
                $context->cache->tags($numericTags)->get($numericTagKey) === 'value',
                'Numeric tags are handled (cast to strings, all mode)'
            );
        }

        // Special characters in keys
        $context->cache->put($context->prefixed('edge:special!@#$%'), 'special', 60);
        $result->assert(
            $context->cache->get($context->prefixed('edge:special!@#$%')) === 'special',
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
        $complexTag = $context->prefixed('complex');
        $complexKey = $context->prefixed('edge:complex');
        $context->cache->tags([$complexTag])->put($complexKey, $complex, 60);

        if ($context->isAnyMode()) {
            $retrieved = $context->cache->get($complexKey);
        } else {
            $retrieved = $context->cache->tags([$complexTag])->get($complexKey);
        }
        $result->assert(
            is_array($retrieved) && $retrieved['nested']['array'][0] === 1,
            'Complex data structures are serialized and deserialized'
        );

        return $result;
    }
}
