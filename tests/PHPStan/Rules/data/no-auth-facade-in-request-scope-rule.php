<?php

namespace Hypervel\Tests\PHPStan\Rules\Data\NoAuthFacadeInRequestScope;

use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;

class NoAuthFacadeInRequestScopeRuleData
{
    public function good(Request $request)
    {
        return $request->user() ? true : false;
    }

    public function badCheck(Request $request)
    {
        return Auth::check();
    }

    public function badUser(Request $request)
    {
        return Auth::user();
    }

    public function badGuest(Request $request)
    {
        return Auth::guest();
    }

    public function goodWithNoRequest()
    {
        return Auth::check();
    }
}

class CustomRequest extends Request
{
    public function foo(): bool
    {
        return Auth::check();
    }

    public static function staticFoo(): bool
    {
        return Auth::check();
    }
}

class MixedCaseMethod
{
    public function check(Request $request): bool
    {
        return Auth::Check();
    }
}
