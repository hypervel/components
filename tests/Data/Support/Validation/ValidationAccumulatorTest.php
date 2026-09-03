<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Closure;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Data\Support\Validation\ValidationAccumulator;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Exists;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;

class ValidationAccumulatorTest extends TestCase
{
    /**
     * Test every compiled output participates in accumulator equality.
     *
     * @param Closure(ValidationAccumulator): void $change
     */
    #[DataProvider('differentOutputProvider')]
    public function testComparesEveryCompiledOutput(Closure $change): void
    {
        $accumulator = $this->makeAccumulator();
        $other = $this->makeAccumulator();

        $this->assertTrue($accumulator->equals($other));

        $change($other);

        $this->assertFalse($accumulator->equals($other));
    }

    /**
     * Provide one change for every compiled accumulator output.
     */
    public static function differentOutputProvider(): array
    {
        return [
            'rules' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->rules['name'] = ['max:10'];
            }],
            'inferred required paths' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->inferredRequiredPaths = [];
            }],
            'messages' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->messages['name.required'] = 'Another message';
            }],
            'attributes' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->attributes['name'] = 'Another attribute';
            }],
            'preserved paths' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->preservedPaths = [ValidationPath::create('items.*.other')];
            }],
            'additional fields' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->additionalFields = ['items.*.other'];
            }],
            'allowed subtrees' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->allowedSubtrees = ['items.*.other'];
            }],
            'finished structural paths' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->finishedStructuralPaths = ['items.*.other' => true];
            }],
            'marker candidates' => [static function (ValidationAccumulator $accumulator): void {
                $accumulator->addMarkerCandidate(
                    ValidationPath::create('items.*.name'),
                    ValidationPath::create('items.1.name'),
                );
            }],
        ];
    }

    /**
     * Test merges retain every marker contributor and finished structural path.
     */
    public function testMergesMarkerCandidateContributors(): void
    {
        $accumulator = new ValidationAccumulator;
        $accumulator->addMarkerCandidate(
            ValidationPath::create('items.*.name'),
            ValidationPath::create('items.0.name'),
        );
        $other = new ValidationAccumulator;
        $other->addMarkerCandidate(
            ValidationPath::create('items.*.name'),
            ValidationPath::create('items.1.name'),
        );
        $other->finishedStructuralPaths['items.*.profile'] = true;

        $accumulator->merge($other);

        $this->assertSame([
            'items.*.name' => [
                'items.0.name' => true,
                'items.1.name' => true,
            ],
        ], $accumulator->markerCandidates);
        $this->assertSame([
            'items.*.profile' => true,
        ], $accumulator->finishedStructuralPaths);
    }

    /**
     * Test preserved paths compare by their canonical value rather than identity.
     */
    public function testComparesPreservedPathsByCanonicalValue(): void
    {
        $accumulator = new ValidationAccumulator;
        $accumulator->preservedPaths[] = ValidationPath::create('items.*.literal\*.name');
        $other = new ValidationAccumulator;
        $other->preservedPaths[] = ValidationPath::create()
            ->property('items')
            ->wildcard()
            ->item('literal*')
            ->property('name');

        $this->assertTrue($accumulator->equals($other));
    }

    /**
     * Test rules reduced to strings by Validation compare by that result.
     */
    public function testComparesStringReducedRuleValuesByTheirRenderedForm(): void
    {
        $accumulator = new ValidationAccumulator;
        $accumulator->rules = ['name' => [new StringableRuleFixture('same')]];
        $other = new ValidationAccumulator;
        $other->rules = ['name' => ['same']];

        $this->assertTrue($accumulator->equals($other));

        $other->rules = ['name' => [new StringableRuleFixture('different')]];

        $this->assertFalse($accumulator->equals($other));
    }

    /**
     * Test Validator rule objects compare by identity unless Validation stringifies them.
     */
    public function testComparesOrdinaryRuleObjectsByIdentity(): void
    {
        $rule = new IdentityRuleFixture;
        $accumulator = new ValidationAccumulator;
        $accumulator->rules = ['name' => [$rule]];
        $other = new ValidationAccumulator;
        $other->rules = ['name' => [$rule]];

        $this->assertTrue($accumulator->equals($other));

        $other->rules = ['name' => [new IdentityRuleFixture]];

        $this->assertFalse($accumulator->equals($other));
    }

    /**
     * Test callback-bearing database rules compare their rendered query and callbacks.
     */
    public function testComparesDatabaseRuleCallbacksByIdentity(): void
    {
        $callback = static fn (): null => null;
        $accumulator = new ValidationAccumulator;
        $accumulator->rules = [
            'email' => [(new Exists('users', 'email'))->using($callback)],
        ];
        $other = new ValidationAccumulator;
        $other->rules = [
            'email' => [(new Exists('users', 'email'))->using($callback)],
        ];

        $this->assertTrue($accumulator->equals($other));

        $other->rules = [
            'email' => [(new Exists('users', 'email'))->using(static fn (): null => null)],
        ];

        $this->assertFalse($accumulator->equals($other));
    }

    /**
     * Create one fully populated accumulator.
     */
    protected function makeAccumulator(): ValidationAccumulator
    {
        $accumulator = new ValidationAccumulator;
        $accumulator->rules = ['name' => ['required', 'string']];
        $accumulator->inferredRequiredPaths = ['name' => true];
        $accumulator->messages = ['name.required' => 'The name is required.'];
        $accumulator->attributes = ['name' => 'display name'];
        $accumulator->preservedPaths = [ValidationPath::create('items.*.name')];
        $accumulator->additionalFields = ['items.*.server'];
        $accumulator->allowedSubtrees = ['items.*.metadata'];
        $accumulator->finishedStructuralPaths = ['items.*.profile' => true];
        $accumulator->addMarkerCandidate(
            ValidationPath::create('items.*.name'),
            ValidationPath::create('items.0.name'),
        );

        return $accumulator;
    }
}

class StringableRuleFixture implements Stringable
{
    /**
     * Create a stringable rule fixture.
     */
    public function __construct(
        protected readonly string $value,
    ) {
    }

    /**
     * Get the rendered rule.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}

class IdentityRuleFixture implements Rule
{
    /**
     * Determine if the rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        return true;
    }

    /**
     * Get the validation message.
     */
    public function message(): string
    {
        return 'The value is invalid.';
    }
}
