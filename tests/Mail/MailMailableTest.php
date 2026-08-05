<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Closure;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Mail\Attachable;
use Hypervel\Contracts\Mail\Mailer as MailerContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Mail\Attachment;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailables\Envelope;
use Hypervel\Mail\Mailables\Headers;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Message;
use Hypervel\Mail\Transport\ArrayTransport;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Mime\Email;

class MailMailableTest extends TestCase
{
    public function testMailableSetsRecipientsCorrectly(): void
    {
        $this->mockContainer();

        $mailable = new WelcomeMailableStub;
        $mailable->to('taylor@laravel.com');
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->to);
        $this->assertTrue($mailable->hasTo('taylor@laravel.com'));
        $mailable->assertHasTo('taylor@laravel.com');
        $mailable->to('taylor@laravel.com', 'Taylor Otwell');

        // Add the same recipient again, but with a different name. This should set the name correctly.
        $this->assertTrue($mailable->hasTo('taylor@laravel.com', 'Taylor Otwell'));
        $mailable->assertHasTo('taylor@laravel.com', 'Taylor Otwell');

        $mailable = new WelcomeMailableStub;
        $mailable->to('taylor@laravel.com', 'Taylor Otwell');
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->to);
        $this->assertTrue($mailable->hasTo('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasTo('taylor@laravel.com'));
        $mailable->assertHasTo('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertHasTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->to(['taylor@laravel.com']);
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->to);
        $this->assertTrue($mailable->hasTo('taylor@laravel.com'));
        $this->assertFalse($mailable->hasTo('taylor@laravel.com', 'Taylor Otwell'));
        $mailable->assertHasTo('taylor@laravel.com');
        try {
            $mailable->assertHasTo('taylor@laravel.com', 'Taylor Otwell');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not see expected recipient in email 'to' recipients.\nExpected: [taylor@laravel.com (Taylor Otwell)]\nActual: [taylor@laravel.com]\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new WelcomeMailableStub;
        $mailable->to([['name' => 'Taylor Otwell', 'email' => 'taylor@laravel.com']]);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->to);
        $this->assertTrue($mailable->hasTo('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasTo('taylor@laravel.com'));
        $mailable->assertHasTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->to(new MailableTestUserStub);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->to);
        $this->assertTrue($mailable->hasTo(new MailableTestUserStub));
        $this->assertTrue($mailable->hasTo('taylor@laravel.com'));
        $mailable->assertHasTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->to(collect([new MailableTestUserStub]));
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->to);
        $this->assertTrue($mailable->hasTo(new MailableTestUserStub));
        $this->assertTrue($mailable->hasTo('taylor@laravel.com'));
        $mailable->assertHasTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->to(collect([new MailableTestUserStub, new MailableTestUserStub, new MailableTestUserStub2]));
        $this->assertEquals([
            ['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com'],
            ['name' => 'Laravel Framework', 'address' => 'contact@laravel.com'],
        ], $mailable->to);
        $this->assertTrue($mailable->hasTo(new MailableTestUserStub));
        $this->assertTrue($mailable->hasTo('taylor@laravel.com'));
        $mailable->assertHasTo('taylor@laravel.com');

        foreach (['', []] as $address) {
            $mailable = new WelcomeMailableStub;
            $mailable->to($address);
            $this->assertFalse($mailable->hasTo(new MailableTestUserStub));
            $this->assertFalse($mailable->hasTo($address));
            try {
                $mailable->assertHasTo($address);
                $this->fail();
            } catch (AssertionFailedError $e) {
                if (! is_string($address)) {
                    $address = json_encode($address);
                }
                $this->assertSame("Did not see expected recipient in email 'to' recipients.\nExpected: [{$address}]\nActual: [none]\nFailed asserting that false is true.", $e->getMessage());
            }
        }
    }

    public function testMailableSetsCcRecipientsCorrectly(): void
    {
        $this->mockContainer();

        $mailable = new WelcomeMailableStub;
        $mailable->cc('taylor@laravel.com');
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->cc);
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $mailable->assertHasCc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->cc('taylor@laravel.com', 'Taylor Otwell');
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->cc);
        $this->assertTrue($mailable->hasCc('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $mailable->assertHasCc('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertHasCc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->cc(['taylor@laravel.com']);
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->cc);
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $this->assertFalse($mailable->hasCc('taylor@laravel.com', 'Taylor Otwell'));
        $mailable->assertHasCc('taylor@laravel.com');
        try {
            $mailable->assertHasCc('taylor@laravel.com', 'Taylor Otwell');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not see expected recipient in email 'cc' recipients.\nExpected: [taylor@laravel.com (Taylor Otwell)]\nActual: [taylor@laravel.com]\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new WelcomeMailableStub;
        $mailable->cc([['name' => 'Taylor Otwell', 'email' => 'taylor@laravel.com']]);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->cc);
        $this->assertTrue($mailable->hasCc('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $mailable->assertHasCc('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertHasCc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->cc(new MailableTestUserStub);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->cc);
        $this->assertTrue($mailable->hasCc(new MailableTestUserStub));
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $mailable->assertHasCc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->cc(collect([new MailableTestUserStub]));
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->cc);
        $this->assertTrue($mailable->hasCc(new MailableTestUserStub));
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $mailable->assertHasCc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->cc(collect([new MailableTestUserStub, new MailableTestUserStub, new MailableTestUserStub2]));
        $this->assertEquals([
            ['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com'],
            ['name' => 'Laravel Framework', 'address' => 'contact@laravel.com'],
        ], $mailable->cc);
        $this->assertTrue($mailable->hasCc(new MailableTestUserStub));
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $mailable->assertHasCc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->cc(['taylor@laravel.com', 'not-taylor@laravel.com']);
        $this->assertEquals([
            ['name' => null, 'address' => 'taylor@laravel.com'],
            ['name' => null, 'address' => 'not-taylor@laravel.com'],
        ], $mailable->cc);
        $this->assertTrue($mailable->hasCc('taylor@laravel.com'));
        $this->assertTrue($mailable->hasCc('not-taylor@laravel.com'));
        $mailable->assertHasCc('taylor@laravel.com');
        $mailable->assertHasCc('not-taylor@laravel.com');

        foreach (['', []] as $address) {
            $mailable = new WelcomeMailableStub;
            $mailable->cc($address);
            $this->assertFalse($mailable->hasCc(new MailableTestUserStub));
            $this->assertFalse($mailable->hasCc($address));
            try {
                $mailable->assertHasCc($address);
                $this->fail();
            } catch (AssertionFailedError $e) {
                if (! is_string($address)) {
                    $address = json_encode($address);
                }
                $this->assertSame("Did not see expected recipient in email 'cc' recipients.\nExpected: [{$address}]\nActual: [none]\nFailed asserting that false is true.", $e->getMessage());
            }
        }
    }

    public function testMailableSetsBccRecipientsCorrectly(): void
    {
        $this->mockContainer();

        $mailable = new WelcomeMailableStub;
        $mailable->bcc('taylor@laravel.com');
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $mailable->assertHasBcc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->bcc('taylor@laravel.com', 'Taylor Otwell');
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $mailable->assertHasBcc('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertHasBcc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->bcc(['taylor@laravel.com']);
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $this->assertFalse($mailable->hasBcc('taylor@laravel.com', 'Taylor Otwell'));
        $mailable->assertHasBcc('taylor@laravel.com');
        try {
            $mailable->assertHasBcc('taylor@laravel.com', 'Taylor Otwell');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not see expected recipient in email 'bcc' recipients.\nExpected: [taylor@laravel.com (Taylor Otwell)]\nActual: [taylor@laravel.com]\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new WelcomeMailableStub;
        $mailable->bcc([['name' => 'Taylor Otwell', 'email' => 'taylor@laravel.com']]);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $mailable->assertHasBcc('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertHasBcc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->bcc(new MailableTestUserStub);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc(new MailableTestUserStub));
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $mailable->assertHasBcc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->bcc(collect([new MailableTestUserStub]));
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc(new MailableTestUserStub));
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $mailable->assertHasBcc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->bcc(collect([new MailableTestUserStub, new MailableTestUserStub, new MailableTestUserStub2]));
        $this->assertEquals([
            ['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com'],
            ['name' => 'Laravel Framework', 'address' => 'contact@laravel.com'],
        ], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc(new MailableTestUserStub));
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $mailable->assertHasBcc('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->bcc(['taylor@laravel.com', 'not-taylor@laravel.com']);
        $this->assertEquals([
            ['name' => null, 'address' => 'taylor@laravel.com'],
            ['name' => null, 'address' => 'not-taylor@laravel.com'],
        ], $mailable->bcc);
        $this->assertTrue($mailable->hasBcc('taylor@laravel.com'));
        $this->assertTrue($mailable->hasBcc('not-taylor@laravel.com'));
        $mailable->assertHasBcc('taylor@laravel.com');
        $mailable->assertHasBcc('not-taylor@laravel.com');

        foreach (['', []] as $address) {
            $mailable = new WelcomeMailableStub;
            $mailable->bcc($address);
            $this->assertFalse($mailable->hasBcc(new MailableTestUserStub));
            $this->assertFalse($mailable->hasBcc($address));
            try {
                $mailable->assertHasBcc($address);
                $this->fail();
            } catch (AssertionFailedError $e) {
                if (! is_string($address)) {
                    $address = json_encode($address);
                }
                $this->assertSame("Did not see expected recipient in email 'bcc' recipients.\nExpected: [{$address}]\nActual: [none]\nFailed asserting that false is true.", $e->getMessage());
            }
        }
    }

    public function testMailableSetsReplyToCorrectly(): void
    {
        $this->mockContainer();

        $mailable = new WelcomeMailableStub;
        $mailable->replyTo('taylor@laravel.com');
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->replyTo);
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com'));
        $mailable->assertHasReplyTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->replyTo('taylor@laravel.com', 'Taylor Otwell');
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->replyTo);
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com'));
        $mailable->assertHasReplyTo('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertHasReplyTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->replyTo(['taylor@laravel.com']);
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->replyTo);
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com'));
        $this->assertFalse($mailable->hasReplyTo('taylor@laravel.com', 'Taylor Otwell'));
        $mailable->assertHasReplyTo('taylor@laravel.com');
        try {
            $mailable->assertHasReplyTo('taylor@laravel.com', 'Taylor Otwell');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not see expected address as email 'reply to' recipient.\nExpected: [taylor@laravel.com (Taylor Otwell)]\nActual: [taylor@laravel.com]\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new WelcomeMailableStub;
        $mailable->replyTo([['name' => 'Taylor Otwell', 'email' => 'taylor@laravel.com']]);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->replyTo);
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com'));
        $mailable->assertHasReplyTo('taylor@laravel.com');
        $mailable->assertHasReplyTo('taylor@laravel.com', 'Taylor Otwell');

        $mailable = new WelcomeMailableStub;
        $mailable->replyTo(new MailableTestUserStub);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->replyTo);
        $this->assertTrue($mailable->hasReplyTo(new MailableTestUserStub));
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com'));
        $mailable->assertHasReplyTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->replyTo(collect([new MailableTestUserStub]));
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->replyTo);
        $this->assertTrue($mailable->hasReplyTo(new MailableTestUserStub));
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com'));
        $mailable->assertHasReplyTo('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->replyTo(collect([new MailableTestUserStub, new MailableTestUserStub, new MailableTestUserStub2]));
        $this->assertEquals([
            ['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com'],
            ['name' => 'Laravel Framework', 'address' => 'contact@laravel.com'],
        ], $mailable->replyTo);
        $this->assertTrue($mailable->hasReplyTo(new MailableTestUserStub));
        $this->assertTrue($mailable->hasReplyTo('taylor@laravel.com'));
        $mailable->assertHasReplyTo('taylor@laravel.com');

        foreach (['', []] as $address) {
            $mailable = new WelcomeMailableStub;
            $mailable->replyTo($address);
            $this->assertFalse($mailable->hasReplyTo(new MailableTestUserStub));
            $this->assertFalse($mailable->hasReplyTo($address));
            try {
                $mailable->assertHasReplyTo($address);
                $this->fail();
            } catch (AssertionFailedError $e) {
                if (! is_string($address)) {
                    $address = json_encode($address);
                }
                $this->assertSame("Did not see expected address as email 'reply to' recipient.\nExpected: [{$address}]\nActual: [none]\nFailed asserting that false is true.", $e->getMessage());
            }
        }
    }

    public function testMailableSetsFromCorrectly(): void
    {
        $this->mockContainer();

        $mailable = new WelcomeMailableStub;
        $mailable->from('taylor@laravel.com');
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->from);
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com'));
        $mailable->assertFrom('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->from('taylor@laravel.com', 'Taylor Otwell');
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->from);
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com'));
        $mailable->assertFrom('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertFrom('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->from(['taylor@laravel.com']);
        $this->assertEquals([['name' => null, 'address' => 'taylor@laravel.com']], $mailable->from);
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com'));
        $this->assertFalse($mailable->hasFrom('taylor@laravel.com', 'Taylor Otwell'));
        $mailable->assertFrom('taylor@laravel.com');
        try {
            $mailable->assertFrom('taylor@laravel.com', 'Taylor Otwell');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Email was not from expected address.\nExpected: [taylor@laravel.com (Taylor Otwell)]\nActual: [taylor@laravel.com]\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new WelcomeMailableStub;
        $mailable->from([['name' => 'Taylor Otwell', 'email' => 'taylor@laravel.com']]);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->from);
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com', 'Taylor Otwell'));
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com'));
        $mailable->assertFrom('taylor@laravel.com', 'Taylor Otwell');
        $mailable->assertFrom('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->from(new MailableTestUserStub);
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->from);
        $this->assertTrue($mailable->hasFrom(new MailableTestUserStub));
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com'));
        $mailable->assertFrom('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->from(collect([new MailableTestUserStub]));
        $this->assertEquals([['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com']], $mailable->from);
        $this->assertTrue($mailable->hasFrom(new MailableTestUserStub));
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com'));
        $mailable->assertFrom('taylor@laravel.com');

        $mailable = new WelcomeMailableStub;
        $mailable->from(collect([new MailableTestUserStub, new MailableTestUserStub, new MailableTestUserStub2]));
        $this->assertEquals([
            ['name' => 'Taylor Otwell', 'address' => 'taylor@laravel.com'],
            ['name' => 'Laravel Framework', 'address' => 'contact@laravel.com'],
        ], $mailable->from);
        $this->assertTrue($mailable->hasFrom(new MailableTestUserStub));
        $this->assertTrue($mailable->hasFrom('taylor@laravel.com'));
        $mailable->assertFrom('taylor@laravel.com');

        foreach (['', []] as $address) {
            $mailable = new WelcomeMailableStub;
            $mailable->from($address);
            $this->assertFalse($mailable->hasFrom(new MailableTestUserStub));
            $this->assertFalse($mailable->hasFrom($address));
            try {
                $mailable->assertFrom($address);
                $this->fail();
            } catch (AssertionFailedError $e) {
                if (! is_string($address)) {
                    $address = json_encode($address);
                }
                $this->assertSame("Email was not from expected address.\nExpected: [{$address}]\nActual: [none]\nFailed asserting that false is true.", $e->getMessage());
            }
        }
    }

    public function testMailableSetsSubjectCorrectly(): void
    {
        $mailable = new WelcomeMailableStub;
        $mailable->subject('foo');
        $this->assertTrue($mailable->hasSubject('foo'));
    }

    public function testItIgnoresDuplicatedRawAttachments(): void
    {
        $mailable = new WelcomeMailableStub;

        $mailable->attachData('content1', 'report-1.txt');
        $this->assertCount(1, $mailable->rawAttachments);

        $mailable->attachData('content2', 'report-2.txt');
        $this->assertCount(2, $mailable->rawAttachments);

        $mailable->attachData('content1', 'report-1.txt');
        $mailable->attachData('content2', 'report-2.txt');
        $this->assertCount(2, $mailable->rawAttachments);

        $mailable->attachData('content1', 'report-3.txt');
        $mailable->attachData('content2', 'report-4.txt');
        $this->assertCount(4, $mailable->rawAttachments);

        $this->assertSame([
            [
                'data' => 'content1',
                'name' => 'report-1.txt',
                'options' => [],
            ],
            [
                'data' => 'content2',
                'name' => 'report-2.txt',
                'options' => [],
            ],
            [
                'data' => 'content1',
                'name' => 'report-3.txt',
                'options' => [],
            ],
            [
                'data' => 'content2',
                'name' => 'report-4.txt',
                'options' => [],
            ],
        ], $mailable->rawAttachments);
    }

    public function testItIgnoresDuplicateStorageAttachments(): void
    {
        $mailable = new WelcomeMailableStub;

        $mailable->attachFromStorageDisk('disk1', 'sample/file.txt');
        $this->assertCount(1, $mailable->diskAttachments);

        $mailable->attachFromStorageDisk('disk1', 'sample/file2.txt');
        $this->assertCount(2, $mailable->diskAttachments);

        $mailable->attachFromStorageDisk('disk1', 'sample/file.txt', 'file.txt');
        $mailable->attachFromStorageDisk('disk1', 'sample/file2.txt');
        $this->assertCount(2, $mailable->diskAttachments);

        $mailable->attachFromStorageDisk('disk2', 'sample/file.txt', 'file.txt');
        $mailable->attachFromStorageDisk('disk2', 'sample/file2.txt');
        $this->assertCount(4, $mailable->diskAttachments);

        $mailable->attachFromStorageDisk('disk1', 'sample/file.txt', 'custom.txt');
        $this->assertCount(5, $mailable->diskAttachments);

        $this->assertSame([
            [
                'disk' => 'disk1',
                'path' => 'sample/file.txt',
                'name' => 'file.txt',
                'options' => [],
            ],
            [
                'disk' => 'disk1',
                'path' => 'sample/file2.txt',
                'name' => 'file2.txt',
                'options' => [],
            ],
            [
                'disk' => 'disk2',
                'path' => 'sample/file.txt',
                'name' => 'file.txt',
                'options' => [],
            ],
            [
                'disk' => 'disk2',
                'path' => 'sample/file2.txt',
                'name' => 'file2.txt',
                'options' => [],
            ],
            [
                'disk' => 'disk1',
                'path' => 'sample/file.txt',
                'name' => 'custom.txt',
                'options' => [],
            ],
        ], $mailable->diskAttachments);
    }

    public function testMailableBuildsViewData(): void
    {
        $mailable = new WelcomeMailableStub;

        $mailable->build();

        $expected = [
            'first_name' => 'Taylor',
            'lastName' => 'Otwell',
            'framework' => 'Hypervel',
            '__hypervel_mailable' => get_class($mailable),
        ];

        $this->assertSame($expected, $mailable->buildViewData());
    }

    public function testMailerMayBeSet(): void
    {
        $mailable = new WelcomeMailableStub;

        $mailable->mailer('array');

        $mailable = unserialize(serialize($mailable));

        $this->assertSame('array', $mailable->mailer);
    }

    public function testMailablePriorityGetsSent(): void
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $mailable = new WelcomeMailableStub;
        $mailable->to('hello@laravel.com');
        $mailable->from('taylor@laravel.com');
        $mailable->html('test content');

        $mailable->priority(1);

        $sentMessage = $mailer->send($mailable);

        $this->assertSame('hello@laravel.com', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('X-Priority: 1 (Highest)', $sentMessage->toString());
    }

    public function testEnvelopeCallbackCanInspectAndReplaceRenderedContent(): void
    {
        $view = $this->mockView();
        $mailer = new Mailer('array', $view, new ArrayTransport);
        $renderedContent = null;

        $callback = function (Email $message) use (&$renderedContent): void {
            $renderedContent = $message->getHtmlBody();
            $message->html('callback content');
        };

        $mailable = new class($callback) extends Mailable {
            public function __construct(protected Closure $callback)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: 'taylor@laravel.com',
                    to: ['hello@laravel.com'],
                    using: [$this->callback],
                );
            }

            public function build(): void
            {
                $this->html('rendered by mailable');
            }
        };

        $sentMessage = $mailer->send($mailable);

        $this->assertSame('rendered by mailable', $renderedContent);
        $this->assertStringContainsString('callback content', $sentMessage->toString());
        $this->assertStringNotContainsString('rendered by mailable', $sentMessage->toString());
    }

    public function testMailableMergeMetadata(): void
    {
        $mailable = new WelcomeMailableStub;
        $mailable->to('hello@hypervel.org');
        $mailable->from('taylor@hypervel.org');
        $mailable->html('test content');

        $mailable->metadata([
            'template_id' => 'external-template-id',
            'customer_id' => 101,
            'order_id' => 1000,
            'subtotal' => 1500,
            'gst' => 150,
            'shipping_fee' => 20,
            'total' => 1670,
        ]);

        $this->assertTrue($mailable->hasMetadata('template_id', 'external-template-id'));
        $this->assertTrue($mailable->hasMetadata('customer_id', 101));
        $this->assertTrue($mailable->hasMetadata('order_id', 1000));
        $this->assertTrue($mailable->hasMetadata('subtotal', 1500));
        $this->assertTrue($mailable->hasMetadata('gst', 150));
        $this->assertTrue($mailable->hasMetadata('shipping_fee', 20));
        $this->assertTrue($mailable->hasMetadata('total', 1670));

        $this->mockContainer();
        $view = $this->mockView();
        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send($mailable);
        $this->assertStringContainsString('X-Metadata-template_id: external-template-id', $sentMessage->toString());
        $this->assertStringContainsString('X-Metadata-customer_id: 101', $sentMessage->toString());
        $this->assertStringContainsString('X-Metadata-order_id: 1000', $sentMessage->toString());
        $this->assertStringContainsString('X-Metadata-subtotal: 1500', $sentMessage->toString());
        $this->assertStringContainsString('X-Metadata-gst: 150', $sentMessage->toString());
        $this->assertStringContainsString('X-Metadata-shipping_fee: 20', $sentMessage->toString());
        $this->assertStringContainsString('X-Metadata-total: 1670', $sentMessage->toString());
    }

    public function testMailableMetadataGetsSent(): void
    {
        $this->mockContainer();

        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $mailable = new WelcomeMailableStub;
        $mailable->to('hello@laravel.com');
        $mailable->from('taylor@laravel.com');
        $mailable->html('test content');

        $mailable->metadata('origin', 'test-suite');
        $mailable->metadata('user_id', '1');

        $sentMessage = $mailer->send($mailable);

        $this->assertSame('hello@laravel.com', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('X-Metadata-origin: test-suite', $sentMessage->toString());
        $this->assertStringContainsString('X-Metadata-user_id: 1', $sentMessage->toString());

        $this->assertTrue($mailable->hasMetadata('origin', 'test-suite'));
        $this->assertTrue($mailable->hasMetadata('user_id', '1'));
        $this->assertFalse($mailable->hasMetadata('test', 'test'));
        $mailable->assertHasMetadata('origin', 'test-suite');
        $mailable->assertHasMetadata('user_id', '1');
        try {
            $mailable->assertHasMetadata('test', 'test');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not see expected key [test] and value [test] in email metadata.\nFailed asserting that false is true.", $e->getMessage());
        }
    }

    public function testMailableTagGetsSent(): void
    {
        $this->mockContainer();

        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $mailable = new WelcomeMailableStub;
        $mailable->to('hello@laravel.com');
        $mailable->from('taylor@laravel.com');
        $mailable->html('test content');

        $mailable->tag('test');
        $mailable->tag('foo');

        $sentMessage = $mailer->send($mailable);

        $this->assertSame('hello@laravel.com', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('X-Tag: test', $sentMessage->toString());
        $this->assertStringContainsString('X-Tag: foo', $sentMessage->toString());

        $this->assertTrue($mailable->hasTag('test'));
        $this->assertTrue($mailable->hasTag('foo'));
        $this->assertFalse($mailable->hasTag('bar'));
        $mailable->assertHasTag('test');
        $mailable->assertHasTag('foo');
        try {
            $mailable->assertHasTag('bar');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not see expected tag [bar] in email tags.\nFailed asserting that false is true.", $e->getMessage());
        }
    }

    public function testItCanAttachMultipleFiles(): void
    {
        $mailable = new WelcomeMailableStub;

        $mailable->attachMany([
            '/forge.svg',
            '/vapor.svg' => ['as' => 'Vapor Logo.svg', 'mime' => 'text/css'],
            new class implements Attachable {
                public function toMailAttachment(): Attachment
                {
                    return Attachment::fromPath('/foo.jpg')->as('bar')->withMime('image/png');
                }
            },
        ]);

        $this->assertCount(3, $mailable->attachments);
        $this->assertSame([
            'file' => '/forge.svg',
            'options' => [],
        ], $mailable->attachments[0]);
        $this->assertSame([
            'file' => '/vapor.svg',
            'options' => [
                'as' => 'Vapor Logo.svg',
                'mime' => 'text/css',
            ],
        ], $mailable->attachments[1]);
        $this->assertSame([
            'file' => '/foo.jpg',
            'options' => [
                'as' => 'bar',
                'mime' => 'image/png',
            ],
        ], $mailable->attachments[2]);
    }

    public function testItAttachesFilesViaAttachableContractFromPath(): void
    {
        $mailable = new WelcomeMailableStub;

        $mailable->attach(new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromPath('/foo.jpg')->as('bar')->withMime('image/png');
            }
        });

        $this->assertSame([
            'file' => '/foo.jpg',
            'options' => [
                'as' => 'bar',
                'mime' => 'image/png',
            ],
        ], $mailable->attachments[0]);
    }

    public function testItAttachesFilesViaAttachableContractFromData(): void
    {
        $mailable = new WelcomeMailableStub;

        $mailable->attach(new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'bar', 'foo.jpg')->withMime('image/png');
            }
        });

        $this->assertSame([
            'data' => 'bar',
            'name' => 'foo.jpg',
            'options' => [
                'mime' => 'image/png',
            ],
        ], $mailable->rawAttachments[0]);
    }

    public function testItCanJitNameAttachments(): void
    {
        $mailable = new WelcomeMailableStub;
        $unnamedAttachable = new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'bar')->withMime('image/png');
            }
        };

        $mailable->attach($unnamedAttachable, ['as' => 'foo.jpg']);

        $this->assertSame([
            'data' => 'bar',
            'name' => 'foo.jpg',
            'options' => [
                'mime' => 'image/png',
            ],
        ], $mailable->rawAttachments[0]);
    }

    public function testHasAttachmentWithJitNamedAttachment(): void
    {
        $mailable = new WelcomeMailableStub;
        $unnamedAttachable = new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'bar')->withMime('image/png');
            }
        };

        $mailable->attach($unnamedAttachable, ['as' => 'foo.jpg']);

        $this->assertTrue($mailable->hasAttachment($unnamedAttachable, ['as' => 'foo.jpg']));
    }

    public function testHasAttachmentWithEnvelopeAttachments(): void
    {
        $this->mockContainer();
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope;
            }

            public function attachments(): array
            {
                return [
                    Attachment::fromData(fn () => 'bar')
                        ->withMime('image/png')
                        ->as('foo.jpg'),
                ];
            }
        };
        $unnamedAttachable = new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'bar');
            }
        };

        $mailable->render();

        $this->assertFalse($mailable->hasAttachment($unnamedAttachable));
        $this->assertFalse($mailable->hasAttachment($unnamedAttachable, ['as' => 'foo.jpg']));
        $this->assertFalse($mailable->hasAttachment($unnamedAttachable, ['mime' => 'image/png']));
        $this->assertTrue($mailable->hasAttachment($unnamedAttachable, ['as' => 'foo.jpg', 'mime' => 'image/png']));
    }

    public function testItCanCheckForPathBasedAttachments(): void
    {
        $mailable = new WelcomeMailableStub;
        $mailable->attach('foo.jpg');

        $this->assertTrue($mailable->hasAttachment('foo.jpg'));
        $this->assertTrue($mailable->hasAttachment(Attachment::fromPath('foo.jpg')));
        $this->assertTrue($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('foo.jpg'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg'));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg'))));

        $this->assertFalse($mailable->hasAttachment('foo.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('foo.jpg')->withMime('text/css')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('foo.jpg')->withMime('text/css'))));

        $mailable = new WelcomeMailableStub;
        $mailable->attach('bar.jpg', ['mime' => 'text/css']);

        $this->assertTrue($mailable->hasAttachment('bar.jpg', ['mime' => 'text/css']));
        $this->assertTrue($mailable->hasAttachment(Attachment::fromPath('bar.jpg')->withMime('text/css')));
        $this->assertTrue($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg')->withMime('text/css'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg'));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg', ['mime' => 'text/html']));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')->withMime('text/html')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg')->withMime('text/html'))));
    }

    public function testItCanCheckForAttachmentBasedAttachments(): void
    {
        $mailable = new WelcomeMailableStub;
        $mailable->attach(Attachment::fromPath('foo.jpg'));

        $this->assertTrue($mailable->hasAttachment('foo.jpg'));
        $this->assertTrue($mailable->hasAttachment(Attachment::fromPath('foo.jpg')));
        $this->assertTrue($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('foo.jpg'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg'));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg'))));

        $this->assertFalse($mailable->hasAttachment('foo.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('foo.jpg')->withMime('text/css')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('foo.jpg')->withMime('text/css'))));

        $mailable = new WelcomeMailableStub;
        $mailable->attach(Attachment::fromPath('bar.jpg')->withMime('text/css'));

        $this->assertTrue($mailable->hasAttachment('bar.jpg', ['mime' => 'text/css']));
        $this->assertTrue($mailable->hasAttachment(Attachment::fromPath('bar.jpg')->withMime('text/css')));
        $this->assertTrue($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg')->withMime('text/css'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg'));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg', ['mime' => 'text/html']));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')->withMime('text/html')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg')->withMime('text/html'))));
    }

    public function testItCanCheckForAttachableBasedAttachments(): void
    {
        $mailable = new WelcomeMailableStub;
        $mailable->attach(new MailTestAttachable(Attachment::fromPath('foo.jpg')));

        $this->assertTrue($mailable->hasAttachment('foo.jpg'));
        $this->assertTrue($mailable->hasAttachment(Attachment::fromPath('foo.jpg')));
        $this->assertTrue($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('foo.jpg'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg'));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg'))));

        $this->assertFalse($mailable->hasAttachment('foo.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('foo.jpg')->withMime('text/css')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('foo.jpg')->withMime('text/css'))));

        $mailable = new WelcomeMailableStub;
        $mailable->attach(new MailTestAttachable(Attachment::fromPath('bar.jpg')->withMime('text/css')));

        $this->assertTrue($mailable->hasAttachment('bar.jpg', ['mime' => 'text/css']));
        $this->assertTrue($mailable->hasAttachment(Attachment::fromPath('bar.jpg')->withMime('text/css')));
        $this->assertTrue($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg')->withMime('text/css'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg'));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg'))));

        $this->assertFalse($mailable->hasAttachment('bar.jpg', ['mime' => 'text/html']));
        $this->assertFalse($mailable->hasAttachment(Attachment::fromPath('bar.jpg')->withMime('text/html')));
        $this->assertFalse($mailable->hasAttachment(new MailTestAttachable(Attachment::fromPath('bar.jpg')->withMime('text/html'))));
    }

    public function testItCanCheckForDataBasedAttachments(): void
    {
        $mailable = new WelcomeMailableStub;
        $mailable->attachData('data', 'foo.jpg');

        $this->assertTrue($mailable->hasAttachedData('data', 'foo.jpg'));
        $this->assertFalse($mailable->hasAttachedData('xxxx', 'foo.jpg'));
        $this->assertFalse($mailable->hasAttachedData('data', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachedData('data', 'foo.jpg', ['mime' => 'text/css']));

        $mailable = new WelcomeMailableStub;
        $mailable->attachData('data', 'bar.jpg', ['mime' => 'text/css']);

        $this->assertTrue($mailable->hasAttachedData('data', 'bar.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachedData('xxxx', 'bar.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachedData('data', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachedData('data', 'bar.jpg', ['mime' => 'text/html']));

        $mailable = new WelcomeMailableStub;
        $mailable->attach(Attachment::fromData(fn () => 'data', 'foo.jpg'));

        $this->assertTrue($mailable->hasAttachedData('data', 'foo.jpg'));
        $this->assertFalse($mailable->hasAttachedData('xxxx', 'foo.jpg'));
        $this->assertFalse($mailable->hasAttachedData('data', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachedData('data', 'foo.jpg', ['mime' => 'text/css']));

        $mailable = new WelcomeMailableStub;
        $mailable->attach(Attachment::fromData(fn () => 'data', 'bar.jpg')->withMime('text/css'));

        $this->assertTrue($mailable->hasAttachedData('data', 'bar.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachedData('xxxx', 'bar.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachedData('data', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachedData('data', 'bar.jpg', ['mime' => 'text/html']));
    }

    public function testItCanCheckForStorageBasedAttachments(): void
    {
        $mailable = new WelcomeMailableStub;
        $mailable->attachFromStorageDisk('disk', '/path/to/foo.jpg');

        $this->assertTrue($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('xxxx', '/path/to/foo.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', null, ['mime' => 'text/css']));

        $mailable = new WelcomeMailableStub;
        $mailable->attachFromStorageDisk('disk', '/path/to/foo.jpg', 'bar.jpg');

        $this->assertTrue($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('xxxx', '/path/to/foo.jpg', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', 'bar.jpg', 'bar.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', 'foo.jpg'));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', 'bar.jpg', ['mime' => 'text/css']));

        $mailable = new WelcomeMailableStub;
        $mailable->attachFromStorageDisk('disk', '/path/to/foo.jpg', 'bar.jpg', ['mime' => 'text/css']);

        $this->assertTrue($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', 'bar.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('xxxx', '/path/to/foo.jpg', 'bar.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', 'bar.jpg', 'bar.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', 'foo.jpg', ['mime' => 'text/css']));
        $this->assertFalse($mailable->hasAttachmentFromStorageDisk('disk', '/path/to/foo.jpg', 'bar.jpg', ['mime' => 'text/html']));
    }

    public function testStorageAttachmentOmitsFailedMimeDetection(): void
    {
        $storage = m::mock(FilesystemAdapter::class);
        $storage->shouldReceive('mimeType')->once()->with('report.txt')->andReturnFalse();
        $storage->shouldReceive('get')->once()->with('report.txt')->andReturn('file content');

        $factory = m::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('documents')->andReturn($storage);
        $this->app->instance(FilesystemFactory::class, $factory);

        $mailable = new Mailable;
        $mailable->attachFromStorageDisk('documents', 'report.txt');

        $message = new Message(new Email);
        (new ClassInvoker($mailable))->buildDiskAttachments($message);

        $attachments = $message->getSymfonyMessage()->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('application/octet-stream', $attachments[0]->getMediaType() . '/' . $attachments[0]->getMediaSubtype());
    }

    public function testExplicitStorageAttachmentMimeSkipsDetection(): void
    {
        $storage = m::mock(FilesystemAdapter::class);
        $storage->shouldNotReceive('mimeType');
        $storage->shouldReceive('get')->once()->with('report.txt')->andReturn('file content');

        $factory = m::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('documents')->andReturn($storage);
        $this->app->instance(FilesystemFactory::class, $factory);

        $mailable = new Mailable;
        $mailable->attachFromStorageDisk('documents', 'report.txt', options: ['mime' => 'text/plain']);

        $message = new Message(new Email);
        (new ClassInvoker($mailable))->buildDiskAttachments($message);

        $attachments = $message->getSymfonyMessage()->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('text/plain', $attachments[0]->getMediaType() . '/' . $attachments[0]->getMediaSubtype());
    }

    public function testAssertHasNoAttachments(): void
    {
        $this->mockContainer();

        $mailable = new class extends Mailable {
            public function build(): void
            {
            }
        };

        $mailable->assertHasNoAttachments();

        $mailableWithAttachment = new class extends Mailable {
            public function build(): void
            {
                $this->attach('/path/to/foo.jpg');
            }
        };

        try {
            $mailableWithAttachment->assertHasNoAttachments();
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Expected no attachments', $e->getMessage());
        }
    }

    public function testAssertHasAttachment(): void
    {
        $this->mockContainer();

        $mailable = new class extends Mailable {
            public function build(): void
            {
            }
        };

        try {
            $mailable->assertHasAttachment('/path/to/foo.jpg');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not find the expected attachment.\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new class extends Mailable {
            public function build(): void
            {
                $this->attach('/path/to/foo.jpg');
            }
        };

        $mailable->assertHasAttachment('/path/to/foo.jpg');
    }

    public function testAssertHasAttachedData(): void
    {
        $this->mockContainer();

        $mailable = new class extends Mailable {
            public function build(): void
            {
            }
        };

        try {
            $mailable->assertHasAttachedData('data', 'foo.jpg');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not find the expected attachment.\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new class extends Mailable {
            public function build(): void
            {
                $this->attachData('data', 'foo.jpg');
            }
        };

        $mailable->assertHasAttachedData('data', 'foo.jpg');
    }

    public function testAssertHasAttachmentFromStorage(): void
    {
        $this->mockContainer();

        $mailable = new class extends Mailable {
            public function build(): void
            {
            }
        };

        try {
            $mailable->assertHasAttachmentFromStorage('/path/to/foo.jpg');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not find the expected attachment.\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new class extends Mailable {
            public function build(): void
            {
                $this->attachFromStorage('/path/to/foo.jpg');
            }
        };

        $mailable->assertHasAttachmentFromStorage('/path/to/foo.jpg');
    }

    public function testAssertHasSubject(): void
    {
        $this->mockContainer();

        $mailable = new class extends Mailable {
            public function build(): void
            {
            }
        };

        try {
            $mailable->assertHasSubject('Foo Subject');
            $this->fail();
        } catch (AssertionFailedError $e) {
            $this->assertSame("Did not see expected text [Foo Subject] in email subject.\nFailed asserting that false is true.", $e->getMessage());
        }

        $mailable = new class extends Mailable {
            public function build(): void
            {
                $this->subject('Foo Subject');
            }
        };

        $mailable->assertHasSubject('Foo Subject');
    }

    public function testMailableHeadersGetSent(): void
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $mailable = new MailableHeadersStub;
        $mailable->to('hello@laravel.com');
        $mailable->from('taylor@laravel.com');
        $mailable->html('test content');

        $sentMessage = $mailer->send($mailable);

        $this->assertSame('custom-message-id@example.com', $sentMessage->getMessageId());

        $this->assertTrue($sentMessage->getOriginalMessage()->getHeaders()->has('references'));
        $this->assertEquals('References', $sentMessage->getOriginalMessage()->getHeaders()->get('references')->getName());
        $this->assertEquals('<previous-message@example.com>', $sentMessage->getOriginalMessage()->getHeaders()->get('references')->getValue());

        $this->assertTrue($sentMessage->getOriginalMessage()->getHeaders()->has('x-custom-header'));
        $this->assertEquals('X-Custom-Header', $sentMessage->getOriginalMessage()->getHeaders()->get('x-custom-header')->getName());
        $this->assertEquals('Custom Value', $sentMessage->getOriginalMessage()->getHeaders()->get('x-custom-header')->getValue());
    }

    public function testMailableAttributesInBuild(): void
    {
        $this->mockContainer();

        $mailable = new class extends Mailable {
            public function build(): void
            {
                $this
                    ->to('hello@laravel.com')
                    ->replyTo('taylor@laravel.com')
                    ->cc('cc@laravel.com', 'Taylor Otwell')
                    ->bcc('bcc@laravel.com', 'Taylor Otwell')
                    ->tag('test-tag')
                    ->metadata('origin', 'test-suite')
                    ->metadata('user_id', '1')
                    ->subject('test subject');
            }
        };

        $mailable->assertTo('hello@laravel.com');
        $mailable->assertHasReplyTo('taylor@laravel.com');
        $mailable->assertHasCc('cc@laravel.com', 'Taylor Otwell');
        $mailable->assertHasBcc('bcc@laravel.com', 'Taylor Otwell');
        $mailable->assertHasTag('test-tag');
        $mailable->assertHasMetadata('origin', 'test-suite');
        $mailable->assertHasMetadata('user_id', '1');
        $mailable->assertHasSubject('test subject');
    }

    public function testMailablesCanBeTapped(): void
    {
        $this->mockContainer();

        $mail = new WelcomeMailableStub;

        $mail->tap(fn ($mailable) => $mailable->to('taylor@hypervel.org', 'Taylor Otwell'));
        $mail->tap(fn ($mailable) => $mailable->subject('Test Subject!'));

        $mail->tap(function ($mailable) {
            $mailable->assertTo('taylor@hypervel.org')
                ->assertHasSubject('Test Subject!');
        });
    }

    protected function mockContainer(): void
    {
        $mailer = m::mock(MailerContract::class);
        $mailer->shouldReceive('render')
            ->andReturn('');

        $this->app->singleton('mail.manager', fn ($app) => new MailManager($app));
        $this->app->instance('view', m::mock(ViewFactory::class));
        $this->app->instance('mailer', $mailer);
    }

    protected function mockView(): ViewFactory
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')
            ->andReturn('rendered.view');

        $view = m::mock(ViewFactory::class);
        $view->shouldReceive('make')
            ->andReturn($viewInterface);

        return $view;
    }
}

class MailableHeadersStub extends Mailable
{
    public function headers(): Headers
    {
        return new Headers('custom-message-id@example.com', [
            'previous-message@example.com',
        ], [
            'X-Custom-Header' => 'Custom Value',
        ]);
    }
}

class WelcomeMailableStub extends Mailable
{
    public string $framework = 'Hypervel';

    protected string $version = '1.0';

    /**
     * Build the message.
     */
    public function build(): void
    {
        $this->with('first_name', 'Taylor')
            ->withLastName('Otwell');
    }
}

class MailableTestUserStub
{
    public string $name = 'Taylor Otwell';

    public string $email = 'taylor@laravel.com';
}

class MailableTestUserStub2
{
    public string $name = 'Laravel Framework';

    public string $email = 'contact@laravel.com';
}

class MailTestAttachable implements Attachable
{
    public function __construct(protected Attachment $attachment)
    {
    }

    public function toMailAttachment(): Attachment
    {
        return $this->attachment;
    }
}
