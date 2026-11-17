<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Auth\Access\Gate;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\Can;
use Hypervel\Validation\Validator;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;
use stdClass;

class ValidationRuleCanTest extends TestCase
{
    use VerifiesDoubles;

    protected $user;

    protected $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Double::for(Authenticatable::class)->strict();

        $this->app->singleton(GateContract::class, function () {
            return new Gate($this->app, function () {
                return $this->user;
            });
        });

        $this->app->singleton(
            TranslatorContract::class,
            fn () => new Translator(
                new ArrayLoader,
                'en'
            )
        );
    }

    public function testValidationFails(): void
    {
        $this->gate()->define('update-company', function ($user, $value) {
            $this->assertSame('1', $value);

            return false;
        });

        $v = new Validator(
            resolve('translator'),
            ['company' => '1'],
            ['company' => new Can('update-company')]
        );

        $this->assertTrue($v->fails());
    }

    public function testValidationPasses(): void
    {
        $this->gate()->define('update-company', function ($user, $class, $model, $value) {
            $this->assertEquals(\App\Models\Company::class, $class);
            $this->assertInstanceOf(Authenticatable::class, $model);
            $this->assertSame('1', $value);

            return true;
        });

        $v = new Validator(
            resolve('translator'),
            ['company' => '1'],
            ['company' => new Can('update-company', [\App\Models\Company::class, Double::for(Authenticatable::class)->strict()])]
        );

        $this->assertTrue($v->passes());
    }

    #[TestWith([0, []])]
    #[TestWith(['0', []])]
    #[TestWith([false, []])]
    #[TestWith([null, []])]
    #[TestWith(['0', ['scope', false, 0, null, '']])]
    public function testAuthorizationReceivesFalseyValuesAndArguments(mixed $value, array $arguments): void
    {
        foreach ([true, false] as $allowed) {
            $this->gate()->define('check-value', function ($user, ...$received) use ($value, $arguments, $allowed): bool {
                $this->assertSame([...$arguments, $value], $received);

                return $allowed;
            });

            $validator = new Validator(
                $this->app->make('translator'),
                ['value' => $value],
                ['value' => new Can('check-value', $arguments)],
            );

            $this->assertSame($allowed, $validator->passes());
        }
    }

    public function testCustomMessageUsingDotNotationAndFqcnWorks(): void
    {
        $v = new Validator(
            $this->app->make('translator'),
            [
                'company' => '1',
                'company_fqcn' => '1',
            ],
            [
                'company' => new Can('update-company', [\App\Models\Company::class, new stdClass]),
                'company_fqcn' => new Can('update-company', [\App\Models\Company::class, new stdClass]),
            ],
            [
                'company.can' => 'You dont have permission (dot notation)',
                'company_fqcn.Hypervel\Validation\Rules\Can' => 'You dont have permission (fqcn)',
            ]
        );

        $this->assertTrue($v->fails());

        $this->assertSame([
            'You dont have permission (dot notation)',
            'You dont have permission (fqcn)',
        ], $v->messages()->all());
    }

    /**
     * Get the Gate instance from the container.
     */
    protected function gate()
    {
        return $this->app->make(GateContract::class);
    }
}
