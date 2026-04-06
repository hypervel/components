<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Mail\Mailable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MailMailableDataTest extends TestCase
{
    public function testMailableDataIsNotLost()
    {
        $testData = ['first_name' => 'James'];

        $mailable = new MailableStub;
        $mailable->build(function ($m) use ($testData) {
            $m->view('view', $testData);
        });
        $expected = array_merge($testData, ['__hypervel_mailable' => MailableStub::class]);
        $this->assertSame($expected, $mailable->buildViewData());

        $mailable = new MailableStub;
        $mailable->build(function ($m) use ($testData) {
            $m->view('view', $testData)
                ->text('text-view');
        });
        $this->assertSame($expected, $mailable->buildViewData());
    }
}

class MailableStub extends Mailable
{
    /**
     * Build the message.
     *
     * @param mixed $builder
     * @return $this
     */
    public function build($builder)
    {
        $builder($this);
    }
}
