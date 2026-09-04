<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\Enum;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

include_once 'Enums.php';

class ValidationEnumRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(
            TranslatorContract::class,
            fn () => new Translator(
                new ArrayLoader,
                'en'
            )
        );
    }

    public function testValidationPassesWhenPassingCorrectEnum(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 'pending',
                'int_status' => 1,
            ],
            [
                'status' => new Enum(StringStatus::class),
                'int_status' => new Enum(IntegerStatus::class),
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationPassesWhenPassingInstanceOfEnum(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => StringStatus::Done,
            ],
            [
                'status' => new Enum(StringStatus::class),
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationPassesWhenPassingInstanceOfPureEnum(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => PureEnum::one,
            ],
            [
                'status' => new Enum(PureEnum::class),
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationFailsWhenProvidingNoExistingCases(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 'finished',
            ],
            [
                'status' => new Enum(StringStatus::class),
            ]
        );

        $this->assertTrue($v->fails());
        $this->assertEquals(['The selected status is invalid.'], $v->messages()->get('status'));
    }

    public function testValidationPassesForAllCasesUntilEitherOnlyOrExceptIsPassed(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status_1' => PureEnum::one,
                'status_2' => PureEnum::two,
                'status_3' => IntegerStatus::Done->value,
            ],
            [
                'status_1' => new Enum(PureEnum::class),
                'status_2' => (new Enum(PureEnum::class))->only([])->except([]),
                'status_3' => new Enum(IntegerStatus::class),
            ],
        );

        $this->assertTrue($v->passes());
    }

    #[DataProvider('conditionalCasesDataProvider')]
    public function testValidationPassesWhenOnlyCasesProvided(
        int|IntegerStatus $enum,
        array|Arrayable|IntegerStatus $only,
        bool $expected
    ): void {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => $enum,
            ],
            [
                'status' => (new Enum(IntegerStatus::class))->only($only),
            ],
        );

        $this->assertSame($expected, $v->passes());
    }

    #[DataProvider('conditionalCasesDataProvider')]
    public function testValidationPassesWhenExceptCasesProvided(
        int|IntegerStatus $enum,
        array|Arrayable|IntegerStatus $except,
        bool $expected
    ): void {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => $enum,
            ],
            [
                'status' => (new Enum(IntegerStatus::class))->except($except),
            ],
        );

        $this->assertSame($expected, $v->fails());
    }

    public static function conditionalCasesDataProvider(): array
    {
        return [
            [IntegerStatus::Done, IntegerStatus::Done, true],
            [IntegerStatus::Done, [IntegerStatus::Done, IntegerStatus::Pending], true],
            [IntegerStatus::Done, new Collection([IntegerStatus::Done, IntegerStatus::Pending]), true],
            [IntegerStatus::Pending->value, [IntegerStatus::Done, IntegerStatus::Pending], true],
            [IntegerStatus::Done->value, IntegerStatus::Pending, false],
        ];
    }

    public function testOnlyHasHigherOrderThanExcept(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => PureEnum::one,
            ],
            [
                'status' => (new Enum(PureEnum::class))
                    ->only(PureEnum::one)
                    ->except(PureEnum::one),
            ],
        );

        $this->assertTrue($v->passes());
    }

    public function testValidationFailsWhenProvidingDifferentType(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 10,
            ],
            [
                'status' => new Enum(StringStatus::class),
            ]
        );

        $this->assertTrue($v->fails());
        $this->assertEquals(['The selected status is invalid.'], $v->messages()->get('status'));
    }

    public function testValidationPassesWhenProvidingDifferentTypeThatIsCastableToTheEnumType(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => '1',
            ],
            [
                'status' => new Enum(IntegerStatus::class),
            ]
        );

        $this->assertFalse($v->fails());

        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 1,
            ],
            [
                'status' => new Enum(IntegerStatus::class),
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationFailsWhenProvidingNull(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => null,
            ],
            [
                'status' => new Enum(StringStatus::class),
            ]
        );

        $this->assertTrue($v->fails());
        $this->assertEquals(['The selected status is invalid.'], $v->messages()->get('status'));
    }

    public function testValidationPassesWhenProvidingNullButTheFieldIsNullable(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => null,
            ],
            [
                'status' => ['nullable', new Enum(StringStatus::class)],
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationFailsOnPureEnum(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 'one',
            ],
            [
                'status' => ['required', new Enum(PureEnum::class)],
            ]
        );

        $this->assertTrue($v->fails());
    }

    public function testValidationFailsWhenProvidingStringToIntegerType(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 'abc',
            ],
            [
                'status' => new Enum(IntegerStatus::class),
            ]
        );

        $this->assertTrue($v->fails());
        $this->assertEquals(['The selected status is invalid.'], $v->messages()->get('status'));
    }

    public function testValidationFailsWhenUsingDifferentCase(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 'DONE',
            ],
            [
                'status' => new Enum(StringStatus::class),
            ]
        );

        $this->assertTrue($v->fails());
        $this->assertEquals(['The selected status is invalid.'], $v->messages()->get('status'));
    }

    public function testCustomMessageUsingDotNotationAndFqcnWorks(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'status' => 'invalid_value',
                'status_fqcn' => 'another_invalid',
            ],
            [
                'status' => new Enum(StringStatus::class),
                'status_fqcn' => new Enum(StringStatus::class),
            ],
            [
                'status.enum' => 'Please choose a valid status (dot notation)',
                'status_fqcn.Hypervel\Validation\Rules\Enum' => 'Please choose a valid status (fqcn)',
            ]
        );

        $this->assertTrue($v->fails());

        $this->assertSame([
            'Please choose a valid status (dot notation)',
            'Please choose a valid status (fqcn)',
        ], $v->messages()->all());
    }

    public function testEnumRuleIsStringable(): void
    {
        $rule = new Enum(StringStatus::class);

        $this->assertSame('in:"pending","done"', (string) $rule);
    }

    public function testEnumRuleStringableWithOnly(): void
    {
        $rule = (new Enum(StringStatus::class))->only([StringStatus::Pending]);

        $this->assertSame('in:"pending"', (string) $rule);
    }

    public function testEnumRuleStringableWithExcept(): void
    {
        $rule = (new Enum(StringStatus::class))->except([StringStatus::Pending]);

        $this->assertSame('in:"done"', (string) $rule);
    }
}
