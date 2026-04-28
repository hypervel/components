<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Providers;

use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;

class FoundationServiceProviderTest extends TestCase
{
    public function testRequestHasValidSignatureMacroIsRegistered()
    {
        $this->assertTrue(Request::hasMacro('hasValidSignature'));
    }

    public function testRequestHasValidRelativeSignatureMacroIsRegistered()
    {
        $this->assertTrue(Request::hasMacro('hasValidRelativeSignature'));
    }

    public function testRequestHasValidSignatureWhileIgnoringMacroIsRegistered()
    {
        $this->assertTrue(Request::hasMacro('hasValidSignatureWhileIgnoring'));
    }

    public function testRequestHasValidRelativeSignatureWhileIgnoringMacroIsRegistered()
    {
        $this->assertTrue(Request::hasMacro('hasValidRelativeSignatureWhileIgnoring'));
    }

    public function testRequestValidateMacroIsRegistered()
    {
        $this->assertTrue(Request::hasMacro('validate'));
    }

    public function testRequestValidateWithBagMacroIsRegistered()
    {
        $this->assertTrue(Request::hasMacro('validateWithBag'));
    }

    public function testConsoleScheduleSingletonIsRegistered()
    {
        $schedule = $this->app->make(Schedule::class);

        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertSame($schedule, $this->app->make(Schedule::class));
    }
}
