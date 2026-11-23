<?php

namespace RedirectResponse;

use Hypervel\Http\RedirectResponse;
use Hypervel\Tests\PHPStan\Fixtures\Type\CustomRedirectResponse;

use function PHPStan\Testing\assertType;

function test(CustomRedirectResponse $customResponse): void
{
    assertType(RedirectResponse::class, redirect()->back()->withSuccess(true));
    assertType(RedirectResponse::class, redirect()->back()->withCookie('foo'));
    assertType(CustomRedirectResponse::class, $customResponse->withSuccess(true));
    assertType('string', $customResponse->withFlag());
}
