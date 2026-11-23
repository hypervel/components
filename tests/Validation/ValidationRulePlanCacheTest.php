<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Validation\Fixtures\IntegerStatus;
use Hypervel\Validation\AttributePlan;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\InlineCheck;
use Hypervel\Validation\Rule;
use Hypervel\Validation\RuleCompiler;
use Hypervel\Validation\RulePlanCache;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;
use Hypervel\Validation\ValidationRuleParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use stdClass;
use WeakMap;
use WeakReference;

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

        RulePlanCache::put($this->key($rules), $plan);

        $cached = RulePlanCache::get($this->key($rules));

        $this->assertSame($plan, $cached);
    }

    public function testCacheMissReturnsNull(): void
    {
        $this->assertNull(RulePlanCache::get($this->key(['required', 'string'])));
    }

    public function testNonStringElementsReturnNull(): void
    {
        $this->assertNull(RulePlanCache::get($this->key(['required', new stdClass])));
    }

    public function testCanonicalFluentRulesShareCachedPlans(): void
    {
        $firstRule = Rule::in([true, false]);
        $secondRule = Rule::in(['1', '0']);
        $firstRules = (new ValidationRuleParser(['state' => 'active']))->explode([
            'state' => ['required', $firstRule],
        ])->rules['state'];
        $secondRules = (new ValidationRuleParser(['state' => 'inactive']))->explode([
            'state' => ['required', $secondRule],
        ])->rules['state'];
        $plan = $this->compile($firstRules);

        RulePlanCache::put($this->key($firstRules), $plan);

        $this->assertSame(['required', $firstRule], $firstRules);
        $this->assertSame(['required', $secondRule], $secondRules);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame(CheckType::In, $plan->checks[1]->type);
        $this->assertSame($plan, RulePlanCache::get($this->key($secondRules)));
    }

    public function testParameterizedRulesSharePlansWithoutRetainingMutableBuilders(): void
    {
        $rule = Rule::confirmed()->customField('first');
        $reference = WeakReference::create($rule);
        $firstPlan = $this->compile([$rule]);
        RulePlanCache::put($this->key([$rule]), $firstPlan);

        $this->assertSame($firstPlan, RulePlanCache::get($this->key([Rule::confirmed()->customField('first')])));

        $rule->customField('second');

        $this->assertNull(RulePlanCache::get($this->key([$rule])));

        $secondPlan = $this->compile([$rule]);
        RulePlanCache::put($this->key([$rule]), $secondPlan);
        unset($rule);

        $this->assertNull($reference->get());
        $this->assertSame($firstPlan, RulePlanCache::get($this->key([Rule::confirmed()->customField('first')])));
        $this->assertSame($secondPlan, RulePlanCache::get($this->key([Rule::confirmed()->customField('second')])));
        $this->assertSame(['Confirmed', 'first'], $firstPlan->checks[0]->originalRule);
        $this->assertSame(['Confirmed', 'second'], $secondPlan->checks[0]->originalRule);
    }

    public function testParameterizedRulesWithCallbacksRemainUncached(): void
    {
        $rule = new class implements ParameterizedRule {
            use FormatsParameters;

            /**
             * Return a custom rule with an executable parameter.
             */
            public function toArray(): array
            {
                return ['custom', static fn (): bool => true];
            }
        };

        RulePlanCache::put($this->key([$rule]), $this->compile([$rule]));

        $this->assertNull(RulePlanCache::get($this->key([$rule])));
    }

    public function testDatabaseConditionsSharePlansWithoutLosingNativeTypes(): void
    {
        $rule = Rule::exists('users', 'id')
            ->where('payload->enabled', true)
            ->where('status', 'NULL')
            ->whereNot('status', '!draft')
            ->whereIn('tenant', ['first', 'second']);
        $reference = WeakReference::create($rule);
        $plan = $this->compile([$rule]);
        RulePlanCache::put($this->key([$rule]), $plan);
        unset($rule);

        $this->assertNull($reference->get());

        $equivalent = Rule::exists('users', 'id')
            ->where('payload->enabled', true)
            ->where('status', 'NULL')
            ->whereNot('status', '!draft')
            ->whereIn('tenant', ['first', 'second']);

        $this->assertSame($plan, RulePlanCache::get($this->key([$equivalent])));

        $different = Rule::exists('users', 'id')
            ->where('payload->enabled', '1')
            ->where('status', 'NULL')
            ->whereNot('status', '!draft')
            ->whereIn('tenant', ['first', 'second']);

        $this->assertNull(RulePlanCache::get($this->key([$different])));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCapturedMembershipDoesNotFollowLaterReferenceMutations(bool $withCallback): void
    {
        $tenant = 'first';
        $rule = Rule::exists('users', 'id')->whereIn('tenant', [&$tenant]);

        if ($withCallback) {
            $rule->using(static function (): void {});
        }

        $firstPlan = $this->compile([$rule]);
        $firstKey = $this->key([$rule]);
        RulePlanCache::put($firstKey, $firstPlan);
        $tenant = 'second';
        $secondPlan = $this->compile([$rule]);

        $this->assertSame([['tenant', 'in', ['first']]], array_slice($firstPlan->checks[0]->originalRule->conditions->toArray(), 0, 1));
        $this->assertSame([['tenant', 'in', ['second']]], array_slice($secondPlan->checks[0]->originalRule->conditions->toArray(), 0, 1));
        $this->assertSame($withCallback ? null : $firstPlan, RulePlanCache::get($firstKey));
        $this->assertNull(RulePlanCache::get($this->key([$rule])));
    }

    public function testArrayableMembershipIsReadOncePerPreparationAndDetachedFromCachedPlans(): void
    {
        $members = new class implements Arrayable {
            public int $reads = 0;

            public array $values = [IntegerStatus::Pending];

            /**
             * Export the current membership values.
             */
            public function toArray(): array
            {
                ++$this->reads;

                return $this->values;
            }
        };
        $rule = Rule::exists('users', 'id')->whereIn('tenant', $members);
        [$prepared, $key] = RuleCompiler::prepare([$rule], new WeakMap);
        $plan = RuleCompiler::compile($prepared, self::NUMERIC_RULES);
        RulePlanCache::put($key, $plan);

        $this->assertSame(1, $members->reads);
        [, $sameKey] = RuleCompiler::prepare([$rule], new WeakMap);
        $this->assertSame($plan, RulePlanCache::get($sameKey));
        $this->assertSame(2, $members->reads);

        $members->values = [IntegerStatus::Done];
        [$prepared, $changedKey] = RuleCompiler::prepare([$rule], new WeakMap);
        $changedPlan = RuleCompiler::compile($prepared, self::NUMERIC_RULES);

        $this->assertSame(3, $members->reads);
        $this->assertNull(RulePlanCache::get($changedKey));
        $this->assertSame([['tenant', 'in', [1]]], $plan->checks[0]->originalRule->conditions->toArray());
        $this->assertSame([['tenant', 'in', [2]]], $changedPlan->checks[0]->originalRule->conditions->toArray());
    }

    public function testFlushStateClearsCache(): void
    {
        $rules = ['required'];
        $plan = $this->compile($rules);

        RulePlanCache::put($this->key($rules), $plan);
        $this->assertNotNull(RulePlanCache::get($this->key($rules)));

        RulePlanCache::flushState();
        $this->assertNull(RulePlanCache::get($this->key($rules)));
    }

    public function testLruEvictionAtMaxSize(): void
    {
        RulePlanCache::setMaxSize(3);

        $rules1 = ['rule_a'];
        $rules2 = ['rule_b'];
        $rules3 = ['rule_c'];
        $rules4 = ['rule_d'];

        RulePlanCache::put($this->key($rules1), $this->compile($rules1));
        RulePlanCache::put($this->key($rules2), $this->compile($rules2));
        RulePlanCache::put($this->key($rules3), $this->compile($rules3));

        $this->assertNotNull(RulePlanCache::get($this->key($rules1)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules2)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules3)));

        // Adding a 4th entry evicts the least recently used (rules1,
        // since rules2 and rules3 were just accessed by get() above)
        RulePlanCache::put($this->key($rules4), $this->compile($rules4));

        $this->assertNull(RulePlanCache::get($this->key($rules1)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules2)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules3)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules4)));
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

        RulePlanCache::put($this->key($rules1), $plan1);
        RulePlanCache::put($this->key($rules2), $plan2);
        RulePlanCache::put($this->key($rules3), $plan3);

        // Re-put rules2. If put() does not unset the existing slot before the
        // eviction loop, rules1 will be wrongly evicted here and rules2 will
        // not be refreshed to the MRU position.
        RulePlanCache::put($this->key($rules2), $plan2);
        RulePlanCache::put($this->key($rules4), $this->compile($rules4));
        RulePlanCache::put($this->key($rules5), $this->compile($rules5));

        $this->assertNull(RulePlanCache::get($this->key($rules1)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules2)));
        $this->assertNull(RulePlanCache::get($this->key($rules3)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules4)));
        $this->assertNotNull(RulePlanCache::get($this->key($rules5)));
    }

    public function testPutWithNonStringElementsIsNoOp(): void
    {
        RulePlanCache::put($this->key(['required', new stdClass]), $this->compile(['required']));

        // No crash, and cache is still empty for string rules
        $this->assertNull(RulePlanCache::get($this->key(['required'])));
    }

    public function testDifferentRuleArraysAreDifferentKeys(): void
    {
        $rules1 = ['required', 'string'];
        $rules2 = ['required', 'integer'];

        $plan1 = $this->compile($rules1);
        $plan2 = $this->compile($rules2);

        RulePlanCache::put($this->key($rules1), $plan1);
        RulePlanCache::put($this->key($rules2), $plan2);

        $this->assertSame($plan1, RulePlanCache::get($this->key($rules1)));
        $this->assertSame($plan2, RulePlanCache::get($this->key($rules2)));
    }

    public function testDelimiterCharactersCannotCollideAcrossRuleArrays(): void
    {
        $rules1 = ['alpha|beta', 'gamma'];
        $rules2 = ['alpha', 'beta|gamma'];
        $plan1 = $this->compile($rules1);
        $plan2 = $this->compile($rules2);

        RulePlanCache::put($this->key($rules1), $plan1);
        RulePlanCache::put($this->key($rules2), $plan2);

        $this->assertSame($plan1, RulePlanCache::get($this->key($rules1)));
        $this->assertSame($plan2, RulePlanCache::get($this->key($rules2)));
    }

    #[DataProvider('invalidMaxSizes')]
    public function testNonPositiveMaximumSizeIsRejected(int $size): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The rule plan cache size must be at least 1.');

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
        [$prepared] = RuleCompiler::prepare($rules, new WeakMap);

        return RuleCompiler::compile($prepared, self::NUMERIC_RULES);
    }

    /**
     * Prepare the cache key through the same declaration boundary as the validator.
     */
    private function key(array $rules): ?string
    {
        return RuleCompiler::prepare($rules, new WeakMap)[1];
    }
}
