<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UselessConstructs;

use Hypervel\PHPStan\Rules\UselessConstructs\NoUselessValueFunctionCallsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoUselessValueFunctionCallsRule> */
class NoUselessValueFunctionCallsRuleTest extends RuleTestCase
{
    public function testNoFalsePositives(): void
    {
        $this->analyse(
            [
                __DIR__ . '/data/CorrectValueFunctionCall.php',
                __DIR__ . '/../data/NamespacedValueFunction.php',
            ],
            [],
        );
    }

    public function testUselessValueCalls(): void
    {
        $message = "Calling the helper function 'value()' without a closure as the first argument simply returns the first argument without doing anything";

        $this->analyse(
            [
                __DIR__ . '/data/UselessValueFunctionCall.php',
            ],
            [
                [$message, 13],
                [$message, 18],
                [$message, 23],
                [$message, 28],
                [$message, 33],
                [$message, 38],
                [$message, 43],
            ],
        );
    }

    public function testFix(): void
    {
        $this->fix(__DIR__ . '/data/UselessValueFunctionCall.php', __DIR__ . '/data/UselessValueFunctionCallFixed.php');
    }

    protected function getRule(): Rule
    {
        return new NoUselessValueFunctionCallsRule($this->createReflectionProvider());
    }
}
