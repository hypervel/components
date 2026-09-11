<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Facades\Mail;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Mail\Fixtures\TestMail;

class SupportMailTest extends TestCase
{
    public function testItRegisterAndCallMacros(): void
    {
        Mail::macro(
            'test',
            fn (string $string): string => $string === 'foo'
            ? 'it works!'
            : 'it failed.',
        );

        $this->assertSame('it works!', Mail::test('foo'));
    }

    public function testItRegisterAndCallMacrosWhenFaked(): void
    {
        Mail::macro(
            'test',
            fn (string $string): string => $string === 'foo'
            ? 'it works!'
            : 'it failed.',
        );

        Mail::fake();

        $this->assertSame('it works!', Mail::test('foo'));
    }

    public function testEmailSent(): void
    {
        Mail::fake();
        Mail::assertNothingSent();

        Mail::to('hello@hypervel.com')->send(new TestMail);

        Mail::assertSent(TestMail::class);
    }
}
