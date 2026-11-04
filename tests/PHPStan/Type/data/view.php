<?php

namespace HypervelView;

use Hypervel\Tests\PHPStan\Fixtures\Type\CustomView;
use Hypervel\View\View;

use function PHPStan\Testing\assertType;

function test(View $view, CustomView $customView): void
{
    // Upstream narrows view() to the concrete view; Hypervel's helper returns the contract.
    assertType('Hypervel\Contracts\View\Factory', view());
    assertType('Hypervel\Contracts\View\View', view('foo'));
    assertType('Hypervel\Contracts\View\View', view('foo')->with('bar', 'baz'));
    // The contract does not promise the view's dynamic with*() methods.
    assertType('*ERROR*', view('foo')->withFoo('bar'));
    assertType('Hypervel\View\View', $view->withFoo('bar'));
    assertType('Hypervel\Tests\PHPStan\Fixtures\Type\CustomView', $customView->withFoo('bar'));
}
