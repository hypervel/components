<?php

namespace DynamicWithMethods;

use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Http\RedirectResponse;
use Hypervel\Mail\Mailable;
use Hypervel\Tests\PHPStan\Fixtures\Type\CustomRedirectResponse;
use Hypervel\View\View;

use function PHPStan\Testing\assertType;

class WelcomeMail extends Mailable
{
}

/**
 * @method string withCustom(string $value)
 */
class CustomMail extends Mailable
{
}

/**
 * @template TValue
 */
class GenericMail extends Mailable
{
}

/**
 * @param GenericMail<int> $genericMail
 */
function test(Mailable $mailable, WelcomeMail $welcomeMail, CustomMail $customMail, GenericMail $genericMail, View $view, ViewContract $viewContract, CustomRedirectResponse $customResponse): void
{
    assertType(RedirectResponse::class, redirect()->back()->withSuccess(true));
    assertType(RedirectResponse::class, redirect()->back()->withCookie('foo'));
    assertType(CustomRedirectResponse::class, $customResponse->withSuccess(true));
    assertType('string', $customResponse->withFlag());

    assertType(View::class, $view->withFoo('bar'));
    // Hypervel: the view contract, which view() returns, does not promise dynamic with*() methods.
    assertType('*ERROR*', view('foo')->withFoo('bar'));
    assertType('*ERROR*', $viewContract->withFoo('bar'));

    assertType(Mailable::class, $mailable->withFoo('bar'));
    assertType(WelcomeMail::class, $welcomeMail->withFoo('bar'));
    // Hypervel: a declared method keeps its signature, and the receiver keeps its template types.
    assertType('string', $customMail->withCustom('bar'));
    assertType('DynamicWithMethods\GenericMail<int>', $genericMail->withFoo('bar'));

    // Macros take precedence over the dynamic with* methods.
    assertType('Hypervel\Mail\Mailable', $mailable->mailableMacro());
    assertType('DynamicWithMethods\WelcomeMail', $welcomeMail->mailableMacro());
    assertType('Hypervel\View\View', $view->viewMacro());
    assertType('Hypervel\Http\RedirectResponse', redirect()->back()->redirectResponseMacro());

    assertType('*ERROR*', $mailable->doesNotExist());
    assertType('*ERROR*', $view->WithFoo('bar'));
}
