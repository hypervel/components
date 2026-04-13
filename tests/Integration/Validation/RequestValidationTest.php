<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation;

use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;

/**
 * @internal
 * @coversNothing
 */
class RequestValidationTest extends TestCase
{
    public function testValidateMacro(): void
    {
        $request = Request::create('/', 'GET', ['name' => 'Taylor']);

        $validated = $request->validate(['name' => 'string']);

        $this->assertSame(['name' => 'Taylor'], $validated);
    }

    public function testValidateMacroWhenItFails(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create('/', 'GET', ['name' => null]);

        $request->validate(['name' => 'string']);
    }

    public function testValidateWithBagMacro(): void
    {
        $request = Request::create('/', 'GET', ['name' => 'Taylor']);

        $validated = $request->validateWithBag('some_bag', ['name' => 'string']);

        $this->assertSame(['name' => 'Taylor'], $validated);
    }

    public function testValidateWithBagMacroWhenItFails(): void
    {
        $request = Request::create('/', 'GET', ['name' => null]);

        try {
            $request->validateWithBag('some_bag', ['name' => 'string']);
        } catch (ValidationException $validationException) {
            $this->assertSame('some_bag', $validationException->errorBag);
        }
    }
}
