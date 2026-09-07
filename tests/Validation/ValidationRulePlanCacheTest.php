<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\AttributePlan;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\InlineCheck;
use Hypervel\Validation\Rule;
use Hypervel\Validation\RuleCompiler;
use Hypervel\Validation\RulePlanCache;
use Hypervel\Validation\ValidationRuleParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class ValidationRulePlanCacheTest extends TestCase
{
    private const array NUMERIC_RULES = ['Numeric', 'Integer', 'Decimal'];

    protected function setUp(): void
    {
        parent::setUp();

        RulePlanCache::flushState();
    }

    public function testCacheHitReturnsSamePlanInstance(): void
    {
        $rules = ['required', 'string', 'max:255'];
        $plan = $this->compile($rules);

        RulePlanCache::put($rules, $plan);

        $cached = RulePlanCache::get($rules);

        $this->assertSame($plan, $cached);
    }

    public function testCacheMissReturnsNull(): void
    {
        $this->assertNull(RulePlanCache::get(['required', 'string']));
    }

    public function testNonStringElementsReturnNull(): void
    {
        $this->assertNull(RulePlanCache::get(['required', new stdClass]));
    }

    public function testCanonicalFluentRulesShareCachedPlans(): void
    {
        $firstRules = (new ValidationRuleParser(['state' => 'active']))->explode([
            'state' => ['required', Rule::in(['active', 'inactive'])],
        ])->rules['state'];
        $secondRules = (new ValidationRuleParser(['state' => 'inactive']))->explode([
            'state' => ['required', Rule::in(['active', 'inactive'])],
        ])->rules['state'];
        $plan = $this->compile($firstRules);

        RulePlanCache::put($firstRules, $plan);

        $this->assertSame(['required', 'in:"active","inactive"'], $firstRules);
        $this->assertSame($firstRules, $secondRules);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame(CheckType::In, $plan->checks[1]->type);
        $this->assertSame($plan, RulePlanCache::get($secondRules));
    }

    public function testFlushStateClearsCache(): void
    {
        $rules = ['required'];
        $plan = $this->compile($rules);

        RulePlanCache::put($rules, $plan);
        $this->assertNotNull(RulePlanCache::get($rules));

        RulePlanCache::flushState();
        $this->assertNull(RulePlanCache::get($rules));
    }

    public function testLruEvictionAtMaxSize(): void
    {
        RulePlanCache::setMaxSize(3);

        $rules1 = ['rule_a'];
        $rules2 = ['rule_b'];
        $rules3 = ['rule_c'];
        $rules4 = ['rule_d'];

        RulePlanCache::put($rules1, $this->compile($rules1));
        RulePlanCache::put($rules2, $this->compile($rules2));
        RulePlanCache::put($rules3, $this->compile($rules3));

        $this->assertNotNull(RulePlanCache::get($rules1));
        $this->assertNotNull(RulePlanCache::get($rules2));
        $this->assertNotNull(RulePlanCache::get($rules3));

        // Adding a 4th entry evicts the least recently used (rules1,
        // since rules2 and rules3 were just accessed by get() above)
        RulePlanCache::put($rules4, $this->compile($rules4));

        $this->assertNull(RulePlanCache::get($rules1));
        $this->assertNotNull(RulePlanCache::get($rules2));
        $this->assertNotNull(RulePlanCache::get($rules3));
        $this->assertNotNull(RulePlanCache::get($rules4));
    }

    public function testReputtingExistingKeyAtMaxSizeDoesNotEvictAndRefreshesRecency(): void
    {
        // Regression guard for the ordered-associative-array LRU: re-putting an
        // existing key at capacity must not evict anything immediately, and it
        // must refresh that key to most-recently-used.
        RulePlanCache::setMaxSize(3);

        $rules1 = ['rule_a'];
        $rules2 = ['rule_b'];
        $rules3 = ['rule_c'];
        $rules4 = ['rule_d'];
        $rules5 = ['rule_e'];

        $plan1 = $this->compile($rules1);
        $plan2 = $this->compile($rules2);
        $plan3 = $this->compile($rules3);

        RulePlanCache::put($rules1, $plan1);
        RulePlanCache::put($rules2, $plan2);
        RulePlanCache::put($rules3, $plan3);

        // Re-put rules2. If put() does not unset the existing slot before the
        // eviction loop, rules1 will be wrongly evicted here and rules2 will
        // not be refreshed to the MRU position.
        RulePlanCache::put($rules2, $plan2);
        RulePlanCache::put($rules4, $this->compile($rules4));
        RulePlanCache::put($rules5, $this->compile($rules5));

        $this->assertNull(RulePlanCache::get($rules1));
        $this->assertNotNull(RulePlanCache::get($rules2));
        $this->assertNull(RulePlanCache::get($rules3));
        $this->assertNotNull(RulePlanCache::get($rules4));
        $this->assertNotNull(RulePlanCache::get($rules5));
    }

    public function testPutWithNonStringElementsIsNoOp(): void
    {
        RulePlanCache::put(['required', new stdClass], $this->compile(['required']));

        // No crash, and cache is still empty for string rules
        $this->assertNull(RulePlanCache::get(['required']));
    }

    public function testDifferentRuleArraysAreDifferentKeys(): void
    {
        $rules1 = ['required', 'string'];
        $rules2 = ['required', 'integer'];

        $plan1 = $this->compile($rules1);
        $plan2 = $this->compile($rules2);

        RulePlanCache::put($rules1, $plan1);
        RulePlanCache::put($rules2, $plan2);

        $this->assertSame($plan1, RulePlanCache::get($rules1));
        $this->assertSame($plan2, RulePlanCache::get($rules2));
    }

    public function testDelimiterCharactersCannotCollideAcrossRuleArrays(): void
    {
        $rules1 = ['alpha|beta', 'gamma'];
        $rules2 = ['alpha', 'beta|gamma'];
        $plan1 = $this->compile($rules1);
        $plan2 = $this->compile($rules2);

        RulePlanCache::put($rules1, $plan1);
        RulePlanCache::put($rules2, $plan2);

        $this->assertSame($plan1, RulePlanCache::get($rules1));
        $this->assertSame($plan2, RulePlanCache::get($rules2));
    }

    #[DataProvider('invalidMaxSizes')]
    public function testNonPositiveMaximumSizeIsRejected(int $size): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The rule plan cache size must be at least 1.');

        RulePlanCache::setMaxSize($size);
    }

    /**
     * Provide invalid maximum cache sizes.
     */
    public static function invalidMaxSizes(): array
    {
        return [[0], [-1]];
    }

    /**
     * Compile rules with the base validator's canonical numeric-rule set.
     */
    private function compile(array $rules): AttributePlan
    {
        return RuleCompiler::compile($rules, self::NUMERIC_RULES);
    }
}
