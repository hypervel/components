<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hyperf\Contract\Arrayable;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Contracts\Translator as TranslatorContract;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\Enum;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

include_once 'Enums.php';

/**
 * @internal
 * @coversNothing
 */
class ValidationEnumRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            TranslatorContract::class,
            fn () => new Translator(
                new ArrayLoader(),
                'en'
            )
        );
    }

    public function testValidationPassesWhenPassingCorrectEnum()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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

    public function testValidationPassesWhenPassingInstanceOfEnum()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
            [
                'status' => StringStatus::done,
            ],
            [
                'status' => new Enum(StringStatus::class),
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationPassesWhenPassingInstanceOfPureEnum()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
            [
                'status' => PureEnum::one,
            ],
            [
                'status' => new Enum(PureEnum::class),
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationFailsWhenProvidingNoExistingCases()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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

    public function testValidationPassesForAllCasesUntilEitherOnlyOrExceptIsPassed()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
            [
                'status_1' => PureEnum::one,
                'status_2' => PureEnum::two,
                'status_3' => IntegerStatus::done->value,
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
    ) {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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
    ) {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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
            [IntegerStatus::done, IntegerStatus::done, true],
            [IntegerStatus::done, [IntegerStatus::done, IntegerStatus::pending], true],
            [IntegerStatus::done, new Collection([IntegerStatus::done, IntegerStatus::pending]), true],
            [IntegerStatus::pending->value, [IntegerStatus::done, IntegerStatus::pending], true],
            [IntegerStatus::done->value, IntegerStatus::pending, false],
        ];
    }

    public function testOnlyHasHigherOrderThanExcept()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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

    public function testValidationFailsWhenProvidingDifferentType()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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

    public function testValidationPassesWhenProvidingDifferentTypeThatIsCastableToTheEnumType()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
            [
                'status' => '1',
            ],
            [
                'status' => new Enum(IntegerStatus::class),
            ]
        );

        $this->assertTrue($v->fails());

        $v = new Validator(
            $this->app->get(TranslatorContract::class),
            [
                'status' => 1,
            ],
            [
                'status' => new Enum(IntegerStatus::class),
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationFailsWhenProvidingNull()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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

    public function testValidationPassesWhenProvidingNullButTheFieldIsNullable()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
            [
                'status' => null,
            ],
            [
                'status' => ['nullable', new Enum(StringStatus::class)],
            ]
        );

        $this->assertFalse($v->fails());
    }

    public function testValidationFailsOnPureEnum()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
            [
                'status' => 'one',
            ],
            [
                'status' => ['required', new Enum(PureEnum::class)],
            ]
        );

        $this->assertTrue($v->fails());
    }

    public function testValidationFailsWhenProvidingStringToIntegerType()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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

    public function testValidationFailsWhenUsingDifferentCase()
    {
        $v = new Validator(
            $this->app->get(TranslatorContract::class),
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
}
