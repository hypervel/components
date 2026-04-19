<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\RuleCompiler;
use Hypervel\Validation\RulePlanCache;
use stdClass;

class ValidationRulePlanCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RulePlanCache::flushState();
    }

    public function testCacheHitReturnsSamePlanInstance()
    {
        $rules = ['required', 'string', 'max:255'];
        $plan = RuleCompiler::compile($rules);

        RulePlanCache::put($rules, $plan);

        $cached = RulePlanCache::get($rules);

        $this->assertSame($plan, $cached);
    }

    public function testCacheMissReturnsNull()
    {
        $this->assertNull(RulePlanCache::get(['required', 'string']));
    }

    public function testNonStringElementsReturnNull()
    {
        $this->assertNull(RulePlanCache::get(['required', new stdClass]));
    }

    public function testFlushStateClearsCache()
    {
        $rules = ['required'];
        $plan = RuleCompiler::compile($rules);

        RulePlanCache::put($rules, $plan);
        $this->assertNotNull(RulePlanCache::get($rules));

        RulePlanCache::flushState();
        $this->assertNull(RulePlanCache::get($rules));
    }

    public function testLruEvictionAtMaxSize()
    {
        RulePlanCache::setMaxSize(3);

        $rules1 = ['rule_a'];
        $rules2 = ['rule_b'];
        $rules3 = ['rule_c'];
        $rules4 = ['rule_d'];

        RulePlanCache::put($rules1, RuleCompiler::compile($rules1));
        RulePlanCache::put($rules2, RuleCompiler::compile($rules2));
        RulePlanCache::put($rules3, RuleCompiler::compile($rules3));

        $this->assertNotNull(RulePlanCache::get($rules1));
        $this->assertNotNull(RulePlanCache::get($rules2));
        $this->assertNotNull(RulePlanCache::get($rules3));

        // Adding a 4th entry evicts the least recently used (rules1,
        // since rules2 and rules3 were just accessed by get() above)
        RulePlanCache::put($rules4, RuleCompiler::compile($rules4));

        $this->assertNull(RulePlanCache::get($rules1));
        $this->assertNotNull(RulePlanCache::get($rules2));
        $this->assertNotNull(RulePlanCache::get($rules3));
        $this->assertNotNull(RulePlanCache::get($rules4));

        RulePlanCache::setMaxSize(2048);
    }

    public function testPutWithNonStringElementsIsNoOp()
    {
        RulePlanCache::put(['required', new stdClass], RuleCompiler::compile(['required']));

        // No crash, and cache is still empty for string rules
        $this->assertNull(RulePlanCache::get(['required']));
    }

    public function testDifferentRuleArraysAreDifferentKeys()
    {
        $rules1 = ['required', 'string'];
        $rules2 = ['required', 'integer'];

        $plan1 = RuleCompiler::compile($rules1);
        $plan2 = RuleCompiler::compile($rules2);

        RulePlanCache::put($rules1, $plan1);
        RulePlanCache::put($rules2, $plan2);

        $this->assertSame($plan1, RulePlanCache::get($rules1));
        $this->assertSame($plan2, RulePlanCache::get($rules2));
    }
}
