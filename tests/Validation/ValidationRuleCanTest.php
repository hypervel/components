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
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ValidationRuleCanTest extends TestCase
{
    protected $user;

    protected $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = m::mock(Authenticatable::class);

        $this->app->bind(GateContract::class, function () {
            return new Gate($this->app, function () {
                return $this->user;
            });
        });

        $this->app->bind(
            TranslatorContract::class,
            fn () => new Translator(
                new ArrayLoader(),
                'en'
            )
        );
    }

    public function testValidationFails()
    {
        $this->gate()->define('update-company', function ($user, $value) {
            $this->assertEquals('1', $value);

            return false;
        });

        $v = new Validator(
            resolve('translator'),
            ['company' => '1'],
            ['company' => new Can('update-company')]
        );

        $this->assertTrue($v->fails());
    }

    public function testValidationPasses()
    {
        $this->gate()->define('update-company', function ($user, $class, $model, $value) {
            $this->assertEquals(\App\Models\Company::class, $class);
            $this->assertInstanceOf(Authenticatable::class, $model);
            $this->assertEquals('1', $value);

            return true;
        });

        $v = new Validator(
            resolve('translator'),
            ['company' => '1'],
            ['company' => new Can('update-company', [\App\Models\Company::class, m::mock(Authenticatable::class)])]
        );

        $this->assertTrue($v->passes());
    }

    /**
     * Get the Gate instance from the container.
     */
    protected function gate()
    {
        return $this->app->get(GateContract::class);
    }
}
