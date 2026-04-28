<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\ValidationRuleParser;
use ReflectionClass;

class ValidationParserCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ValidationRuleParser::flushState();
    }

    public function testCacheHitReturnsSameResult()
    {
        $result1 = ValidationRuleParser::parse('required');
        $result2 = ValidationRuleParser::parse('required');

        $this->assertSame($result1, $result2);
        $this->assertSame('Required', $result1[0]);

        $result1 = ValidationRuleParser::parse('max:255');
        $result2 = ValidationRuleParser::parse('max:255');

        $this->assertSame($result1, $result2);
        $this->assertSame('Max', $result1[0]);
        $this->assertSame(['255'], $result1[1]);
    }

    public function testNonStringInputsAreNotCached()
    {
        $result = ValidationRuleParser::parse(['required_array_keys', 'name']);

        $this->assertSame('RequiredArrayKeys', $result[0]);
        $this->assertSame(['name'], $result[1]);

        // Array-form rules don't populate the cache - verify the cache
        // is still empty by checking a string rule is freshly parsed
        $this->assertSame(0, $this->getParseCacheSize());
    }

    public function testStringableObjectsAreNotCached()
    {
        $exists = new Exists('users', 'email');

        $result = ValidationRuleParser::parse($exists);

        $this->assertSame('Exists', $result[0]);
        $this->assertSame(0, $this->getParseCacheSize());
    }

    public function testRuleContractObjectsAreNotCached()
    {
        $rule = new class implements RuleContract {
            public function passes(string $attribute, mixed $value): bool
            {
                return true;
            }

            public function message(): array|string
            {
                return '';
            }
        };

        $result = ValidationRuleParser::parse($rule);

        $this->assertSame($rule, $result[0]);
        $this->assertSame(0, $this->getParseCacheSize());
    }

    public function testCacheHandlesRegexPatterns()
    {
        $result1 = ValidationRuleParser::parse('regex:/^[a-z:]+$/');
        $result2 = ValidationRuleParser::parse('regex:/^[a-z:]+$/');

        $this->assertSame($result1, $result2);
        $this->assertSame('Regex', $result1[0]);
        $this->assertSame(['/^[a-z:]+$/'], $result1[1]);
    }

    public function testCacheHandlesNotRegex()
    {
        $result1 = ValidationRuleParser::parse('not_regex:/^[0-9|]+$/');
        $result2 = ValidationRuleParser::parse('not_regex:/^[0-9|]+$/');

        $this->assertSame($result1, $result2);
        $this->assertSame('NotRegex', $result1[0]);
        $this->assertSame(['/^[0-9|]+$/'], $result1[1]);
    }

    public function testFlushStateClearsCache()
    {
        ValidationRuleParser::parse('required');
        $this->assertSame(1, $this->getParseCacheSize());

        ValidationRuleParser::flushState();
        $this->assertSame(0, $this->getParseCacheSize());
    }

    public function testCacheIsBounded()
    {
        $reflection = new ReflectionClass(ValidationRuleParser::class);
        $maxSizeProp = $reflection->getProperty('parseCacheMaxSize');
        $originalMax = $maxSizeProp->getValue();

        $maxSizeProp->setValue(null, 10);

        try {
            for ($i = 0; $i < 20; ++$i) {
                ValidationRuleParser::parse("rule_{$i}:param");
            }

            $this->assertLessThanOrEqual(10, $this->getParseCacheSize());
        } finally {
            $maxSizeProp->setValue(null, $originalMax);
        }
    }

    private function getParseCacheSize(): int
    {
        $reflection = new ReflectionClass(ValidationRuleParser::class);
        $cacheProp = $reflection->getProperty('parseCache');

        return count($cacheProp->getValue());
    }
}
