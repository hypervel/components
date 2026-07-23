<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\Date;
use Hypervel\Validation\Validator;

class ValidationDateRuleTest extends TestCase
{
    public function testDefaultDateRule(): void
    {
        $rule = Rule::date();
        $this->assertEquals('date', (string) $rule);

        $rule = new Date;
        $this->assertSame('date', (string) $rule);
    }

    public function testDateFormatRule(): void
    {
        $rule = Rule::date()->format('d/m/Y');
        $this->assertEquals('date_format:d/m/Y', (string) $rule);
    }

    public function testAfterTodayRule(): void
    {
        $rule = Rule::date()->afterToday();
        $this->assertEquals('date|after:today', (string) $rule);

        $rule = Rule::date()->todayOrAfter();
        $this->assertEquals('date|after_or_equal:today', (string) $rule);
    }

    public function testBeforeTodayRule(): void
    {
        $rule = Rule::date()->beforeToday();
        $this->assertEquals('date|before:today', (string) $rule);

        $rule = Rule::date()->todayOrBefore();
        $this->assertEquals('date|before_or_equal:today', (string) $rule);
    }

    public function testAfterSpecificDateRule(): void
    {
        $rule = Rule::date()->after(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date|after:2024-01-01', (string) $rule);

        $rule = Rule::date()->format('d/m/Y')->after(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date_format:d/m/Y|after:01/01/2024', (string) $rule);
    }

    public function testBeforeSpecificDateRule(): void
    {
        $rule = Rule::date()->before(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date|before:2024-01-01', (string) $rule);

        $rule = Rule::date()->format('d/m/Y')->before(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date_format:d/m/Y|before:01/01/2024', (string) $rule);
    }

    public function testAfterOrEqualSpecificDateRule(): void
    {
        $rule = Rule::date()->afterOrEqual(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date|after_or_equal:2024-01-01', (string) $rule);

        $rule = Rule::date()->format('d/m/Y')->afterOrEqual(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date_format:d/m/Y|after_or_equal:01/01/2024', (string) $rule);
    }

    public function testBeforeOrEqualSpecificDateRule(): void
    {
        $rule = Rule::date()->beforeOrEqual(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date|before_or_equal:2024-01-01', (string) $rule);

        $rule = Rule::date()->format('d/m/Y')->beforeOrEqual(CarbonImmutable::parse('2024-01-01'));
        $this->assertEquals('date_format:d/m/Y|before_or_equal:01/01/2024', (string) $rule);
    }

    public function testBetweenDatesRule(): void
    {
        $rule = Rule::date()->between(CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-02-01'));
        $this->assertEquals('date|after:2024-01-01|before:2024-02-01', (string) $rule);

        $rule = Rule::date()->format('d/m/Y')->between(CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-02-01'));
        $this->assertEquals('date_format:d/m/Y|after:01/01/2024|before:01/02/2024', (string) $rule);
    }

    public function testBetweenOrEqualDatesRule(): void
    {
        $rule = Rule::date()->betweenOrEqual('2024-01-01', '2024-02-01');
        $this->assertEquals('date|after_or_equal:2024-01-01|before_or_equal:2024-02-01', (string) $rule);
    }

    public function testChainedRules(): void
    {
        $rule = Rule::date('Y-m-d H:i:s')
            ->format('Y-m-d')
            ->after('2024-01-01 00:00:00')
            ->before('2025-01-01 00:00:00');
        $this->assertEquals('date_format:Y-m-d|after:2024-01-01 00:00:00|before:2025-01-01 00:00:00', (string) $rule);

        $rule = Rule::date()
            ->format('Y-m-d')
            ->when(true, function ($rule) {
                $rule->after('2024-01-01');
            })
            ->unless(true, function ($rule) {
                $rule->before('2025-01-01');
            });
        $this->assertSame('date_format:Y-m-d|after:2024-01-01', (string) $rule);
    }

    public function testDateValidation(): void
    {
        $trans = new Translator(new ArrayLoader, 'en');

        $rule = Rule::date();

        $validator = new Validator(
            $trans,
            ['date' => 'not a date'],
            ['date' => $rule]
        );

        $this->assertSame(
            $trans->get('validation.date'),
            $validator->errors()->first('date')
        );

        $validator = new Validator(
            $trans,
            ['date' => '2024-01-01'],
            ['date' => $rule]
        );

        $this->assertEmpty($validator->errors()->first('date'));

        $rule = Rule::date()->between('2024-01-01', '2025-01-01');

        $validator = new Validator(
            $trans,
            ['date' => '2024-02-01'],
            ['date' => (string) $rule]
        );

        $this->assertEmpty($validator->errors()->first('date'));

        $rule = Rule::date()->between('2024/01/01', '2024/02/01')->format('Y/m/d');

        $validator = new Validator(
            $trans,
            ['date' => '2024/01/15'],
            ['date' => [$rule]]
        );

        $this->assertEmpty($validator->errors()->first('date'));
    }
}
