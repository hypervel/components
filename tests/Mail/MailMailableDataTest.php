<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Closure;
use Hypervel\Mail\Mailable;
use Hypervel\Tests\TestCase;

class MailMailableDataTest extends TestCase
{
    public function testMailableDataIsNotLost(): void
    {
        $testData = ['first_name' => 'James'];

        $mailable = new MailableStub;
        $mailable->build(function (MailableStub $mailable) use ($testData): void {
            $mailable->view('view', $testData);
        });
        $expected = array_merge($testData, ['__hypervel_mailable' => MailableStub::class]);
        $this->assertSame($expected, $mailable->buildViewData());

        $mailable = new MailableStub;
        $mailable->build(function (MailableStub $mailable) use ($testData): void {
            $mailable->view('view', $testData)
                ->text('text-view');
        });
        $this->assertSame($expected, $mailable->buildViewData());
    }
}

class MailableStub extends Mailable
{
    /**
     * Build the message.
     */
    public function build(Closure $builder): void
    {
        $builder($this);
    }
}
