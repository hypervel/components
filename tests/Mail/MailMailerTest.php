<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Mail\Events\MessageSending;
use Hypervel\Mail\Events\MessageSent;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\Message;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Mail\Transport\ArrayTransport;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException as MimeInvalidArgumentException;

class MailMailerTest extends TestCase
{
    use VerifiesDoubles;

    public function testMailerSendSendsMessageWithProperViewContent(): void
    {
        $view = $this->viewFactory('rendered.view');

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertStringContainsString('rendered.view', $sentMessage->toString());
    }

    public function testMailerCallbackCanInspectAndReplaceRenderedContent(): void
    {
        $view = $this->viewFactory('rendered.view');
        $mailer = new Mailer('array', $view, new ArrayTransport);
        $renderedContent = null;

        $sentMessage = $mailer->send('foo', [], function (Message $message) use (&$renderedContent): void {
            $renderedContent = $message->getSymfonyMessage()->getHtmlBody();
            $message->html('callback content')
                ->to('taylor@hypervel.org')
                ->from('hello@hypervel.org');
        });

        $this->assertSame('rendered.view', $renderedContent);
        $this->assertStringContainsString('callback content', $sentMessage->toString());
        $this->assertStringNotContainsString('rendered.view', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithCcAndBccRecipients(): void
    {
        $view = $this->viewFactory('rendered.view');

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')
                ->cc('dries@hypervel.org')
                ->bcc('james@hypervel.org')
                ->from('hello@hypervel.org');
        });

        $recipients = collect($sentMessage->getEnvelope()->getRecipients())->map(function (Address $recipient): string {
            return $recipient->getAddress();
        });

        $this->assertStringContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('dries@hypervel.org', $sentMessage->toString());
        $this->assertStringNotContainsString('james@hypervel.org', $sentMessage->toString());
        $this->assertTrue($recipients->contains('james@hypervel.org'));
    }

    public function testMailerSendSendsMessageWithProperViewContentUsingHtmlStrings(): void
    {
        $view = Double::for(ViewFactory::class)->strict();
        $view->expects('make')->never();

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send(
            ['html' => new HtmlString('<p>Hello Hypervel</p>'), 'text' => new HtmlString('Hello World')],
            ['data'],
            function (Message $message): void {
                $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
            }
        );

        $this->assertStringNotContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('<p>Hello Hypervel</p>', $sentMessage->toString());
        $this->assertStringContainsString('Hello World', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithProperViewContentUsingStringCallbacks(): void
    {
        $view = Double::for(ViewFactory::class)->strict();
        $view->expects('make')->never();

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send(
            [
                'html' => function (array $data): HtmlString {
                    $this->assertInstanceOf(Message::class, $data['message']);

                    return new HtmlString('<p>Hello Hypervel</p>');
                },
                'text' => function (array $data): HtmlString {
                    $this->assertInstanceOf(Message::class, $data['message']);

                    return new HtmlString('Hello World');
                },
            ],
            [],
            function (Message $message): void {
                $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
            }
        );

        $this->assertStringNotContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('<p>Hello Hypervel</p>', $sentMessage->toString());
        $this->assertStringContainsString('Hello World', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithProperViewContentUsingHtmlMethod(): void
    {
        $view = Double::for(ViewFactory::class)->strict();
        $view->expects('make')->never();

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->html('<p>Hello World</p>', function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertStringNotContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('<p>Hello World</p>', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithProperPlainViewContent(): void
    {
        $view = $this->viewFactory('rendered.view', 'rendered.plain');

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send(['foo', 'bar'], ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $expected = <<<Text
        Content-Type: text/html; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.view
        Text;

        $this->assertStringContainsString($expected, $sentMessage->toString());

        $expected = <<<Text
        Content-Type: text/plain; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.plain
        Text;

        $this->assertStringContainsString($expected, $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithProperPlainViewContentWhenExplicit(): void
    {
        $view = $this->viewFactory('rendered.view', 'rendered.plain');

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send(['html' => 'foo', 'text' => 'bar'], ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $expected = <<<Text
        Content-Type: text/html; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.view
        Text;

        $this->assertStringContainsString($expected, $sentMessage->toString());

        $expected = <<<Text
        Content-Type: text/plain; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.plain
        Text;

        $this->assertStringContainsString($expected, $sentMessage->toString());
    }

    public function testMailerSendSendsOnlyPlainContentWhenViewPairHasNoHtmlView(): void
    {
        $view = $this->viewFactory('rendered.plain');

        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->send([null, 'bar'], ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertSame('rendered.plain', $sentMessage->getOriginalMessage()->getTextBody());
        $this->assertNull($sentMessage->getOriginalMessage()->getHtmlBody());
    }

    public function testToAllowsEmailAndName(): void
    {
        $view = $this->viewFactory('rendered.view');
        $mailer = new Mailer('array', $view, new ArrayTransport);

        $sentMessage = $mailer->to('taylor@hypervel.org', 'Taylor Otwell')->send(new TestMail);

        $recipients = $sentMessage->getEnvelope()->getRecipients();
        $this->assertCount(1, $recipients);
        $this->assertSame('taylor@hypervel.org', $recipients[0]->getAddress());
        $this->assertSame('Taylor Otwell', $recipients[0]->getName());
    }

    public function testMailerRejectsAddressesContainingLineBreaks(): void
    {
        $view = $this->viewFactory('rendered.view');
        $mailer = new Mailer('array', $view, new ArrayTransport);

        $this->expectExceptionObject(new InvalidArgumentException('Email addresses may not contain line break characters.'));

        $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to("\"foo\r\nBcc: victim@example.com\"@example.com")->from('hello@hypervel.org');
        });
    }

    public function testMailerRejectsSymfonyAddressesContainingLineBreaks(): void
    {
        $view = $this->viewFactory('rendered.view');
        $mailer = new Mailer('array', $view, new ArrayTransport);

        $this->expectExceptionObject(new MimeInvalidArgumentException('Email address contains control characters.'));

        $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to(new Address("\"foo\r\nBcc: victim@example.com\"@example.com"))->from('hello@hypervel.org');
        });
    }

    public function testGlobalFromIsRespectedOnAllMessages(): void
    {
        $view = $this->viewFactory('rendered.view');
        $mailer = new Mailer('array', $view, new ArrayTransport);
        $mailer->alwaysFrom('hello@hypervel.org');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org');
        });

        $this->assertSame('taylor@hypervel.org', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame('hello@hypervel.org', $sentMessage->getEnvelope()->getSender()->getAddress());
    }

    public function testGlobalReplyToIsRespectedOnAllMessages(): void
    {
        $view = $this->viewFactory('rendered.view');
        $mailer = new Mailer('array', $view, new ArrayTransport);
        $mailer->alwaysReplyTo('taylor@hypervel.org', 'Taylor Otwell');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to('dries@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertSame('dries@hypervel.org', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('Reply-To: Taylor Otwell <taylor@hypervel.org>', $sentMessage->toString());
    }

    public function testGlobalToIsRespectedOnAllMessages(): void
    {
        $view = $this->viewFactory('rendered.view');
        $mailer = new Mailer('array', $view, new ArrayTransport);
        $mailer->alwaysTo('taylor@hypervel.org', 'Taylor Otwell');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message): void {
            $message->from('hello@hypervel.org');
            $message->to('nuno@hypervel.org');
            $message->cc('dries@hypervel.org');
            $message->bcc('james@hypervel.org');
        });

        $recipients = collect($sentMessage->getEnvelope()->getRecipients())->map(function (Address $recipient): string {
            return $recipient->getAddress();
        });

        $this->assertSame('taylor@hypervel.org', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertDoesNotMatchRegularExpression('/^To: nuno@hypervel.org/m', $sentMessage->toString());
        $this->assertDoesNotMatchRegularExpression('/^Cc: dries@hypervel.org/m', $sentMessage->toString());
        $this->assertMatchesRegularExpression('/^X-To: nuno@hypervel.org/m', $sentMessage->toString());
        $this->assertMatchesRegularExpression('/^X-Cc: dries@hypervel.org/m', $sentMessage->toString());
        $this->assertMatchesRegularExpression('/^X-Bcc: james@hypervel.org/m', $sentMessage->toString());
        $this->assertFalse($recipients->contains('nuno@hypervel.org'));
        $this->assertFalse($recipients->contains('dries@hypervel.org'));
        $this->assertFalse($recipients->contains('james@hypervel.org'));
    }

    public function testGlobalReturnPathIsRespectedOnAllMessages(): void
    {
        $view = $this->viewFactory('rendered.view');

        $mailer = new Mailer('array', $view, new ArrayTransport);
        $mailer->alwaysReturnPath('taylorotwell@gmail.com');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertStringContainsString('Return-Path: <taylorotwell@gmail.com>', $sentMessage->toString());
    }

    public function testEventsAreDispatched(): void
    {
        $view = $this->viewFactory('rendered.view');

        $events = new EventFake(new EventDispatcher);

        $mailer = new Mailer('array', $view, new ArrayTransport, $events);

        $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $events->assertDispatchedOnce(MessageSending::class);
        $events->assertDispatchedOnce(MessageSent::class);
    }

    public function testEventsAreSkippedWhenNoListenersAreRegistered(): void
    {
        $view = $this->viewFactory('rendered.view');

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(MessageSending::class)->returns(false);
        $events->expects('hasListeners')->with(MessageSent::class)->returns(false);
        $events->expects('until')->never();
        $events->expects('dispatch')->never();

        $mailer = new Mailer('array', $view, new ArrayTransport, $events);

        $mailer->send('foo', ['data'], function (Message $message): void {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });
    }

    public function testMacroable(): void
    {
        Mailer::macro('foo', function (): string {
            return 'bar';
        });

        $mailer = new Mailer('array', Double::for(ViewFactory::class)->strict(), new ArrayTransport);

        $this->assertSame(
            'bar',
            $mailer->foo()
        );
    }

    public function testSendQueuedMailableReturnsNull(): void
    {
        $view = Double::for(ViewFactory::class)->strict();
        $view->expects('make')->never();
        $queueFake = new QueueFake($this->app);

        $mailer = new Mailer('array', $view, new ArrayTransport);
        $mailer->setQueue($queueFake);

        $mailable = new TestQueuedMail;

        $result = $mailer->send($mailable);

        $this->assertNull($result);

        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    /**
     * Create a view factory that renders the given contents in order.
     */
    protected function viewFactory(string ...$rendered): ViewFactory
    {
        $factory = Double::for(ViewFactory::class)->strict();
        $views = [];

        foreach ($rendered as $contents) {
            $view = Double::for(ViewContract::class)->strict();
            $view->expects('render')->returns($contents);
            $views[] = $view;
        }

        $factory->expects('make')->times(count($views))->returns(...$views);

        return $factory;
    }
}

class TestMail extends Mailable
{
    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('view')
            ->from('hello@hypervel.org');
    }
}

class TestQueuedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('view')
            ->from('hello@hypervel.org')
            ->to('test@hypervel.org')
            ->subject('Test Queued Mail');
    }
}
