<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Support\Facades\Lang;
use Hypervel\Translation\Translator;

class Translation
{
    public function translate(Translator $translator): void
    {
        __('messages.foo');
        trans('messages.foo');
        trans_choice('messages.foo', 1);

        __('messages.test');
        trans('messages.test');
        trans_choice('messages.test', 1);

        $translator->get('messages.bar');
        $translator->choice('messages.bar', 1);

        $translator->get('messages.test');
        $translator->choice('messages.test', 1);

        Lang::get('messages.baz');
        Lang::choice('messages.baz', 1);

        Lang::get('messages.test');
        Lang::choice('messages.test', 1);

        __('foo bar baz');
        __('messages');
        __('messages.nested');
        __('messages.nested.key');

        Lang::get('sub/lines.greeting');
        Lang::get('sub/lines.farewell');

        __('vendor::key');

        __('messages.You\'re beautiful');

        __('messages.A.Key.With.Dots.');

        // Hypervel: method names are case-insensitive.
        $translator->GET('messages.test');
        Lang::Choice('messages.test', 1);
    }
}
