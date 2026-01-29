<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Context\ApplicationContext;
use Hypervel\Mail\Events\MessageSending;
use Hypervel\Mail\Events\MessageSent;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\Message;
use Hypervel\Mail\Transport\ArrayTransport;
use Hypervel\Support\HtmlString;
use Hypervel\View\Contracts\Factory as ViewFactory;
use Hypervel\View\Contracts\View as ViewContract;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class MailMailerTest extends TestCase
{
    protected ?Container $app = null;

    protected function setUp(): void
    {
        $this->app = $this->mockContainer();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['__mailer.test']);

        m::close();
    }

    public function testMailerSendSendsMessageWithProperViewContent()
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertStringContainsString('rendered.view', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithCcAndBccRecipients()
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('taylor@hypervel.org')
                ->cc('dries@hypervel.org')
                ->bcc('james@hypervel.org')
                ->from('hello@hypervel.org');
        });

        $recipients = collect($sentMessage->getEnvelope()->getRecipients())->map(function ($recipient) {
            return $recipient->getAddress();
        });

        $this->assertStringContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('dries@hypervel.org', $sentMessage->toString());
        $this->assertStringNotContainsString('james@hypervel.org', $sentMessage->toString());
        $this->assertTrue($recipients->contains('james@hypervel.org'));
    }

    public function testMailerSendSendsMessageWithProperViewContentUsingHtmlStrings()
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->send(
            ['html' => new HtmlString('<p>Hello Hypervel</p>'), 'text' => new HtmlString('Hello World')],
            ['data'],
            function (Message $message) {
                $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
            }
        );

        $this->assertStringNotContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('<p>Hello Hypervel</p>', $sentMessage->toString());
        $this->assertStringContainsString('Hello World', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithProperViewContentUsingStringCallbacks()
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->send(
            [
                'html' => function ($data) {
                    $this->assertInstanceOf(Message::class, $data['message']);

                    return new HtmlString('<p>Hello Hypervel</p>');
                },
                'text' => function ($data) {
                    $this->assertInstanceOf(Message::class, $data['message']);

                    return new HtmlString('Hello World');
                },
            ],
            [],
            function (Message $message) {
                $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
            }
        );

        $this->assertStringNotContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('<p>Hello Hypervel</p>', $sentMessage->toString());
        $this->assertStringContainsString('Hello World', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithProperViewContentUsingHtmlMethod()
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->html('<p>Hello World</p>', function (Message $message) {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertStringNotContainsString('rendered.view', $sentMessage->toString());
        $this->assertStringContainsString('<p>Hello World</p>', $sentMessage->toString());
    }

    public function testMailerSendSendsMessageWithProperPlainViewContent()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')
            ->once()
            ->andReturn('rendered.view');
        $viewInterface->shouldReceive('render')
            ->once()
            ->andReturn('rendered.plain');

        $view = m::mock(ViewFactory::class);
        $view->shouldReceive('make')->andReturn($viewInterface);

        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->send(['foo', 'bar'], ['data'], function (Message $message) {
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

    public function testMailerSendSendsMessageWithProperPlainViewContentWhenExplicit()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')
            ->once()
            ->andReturn('rendered.view');
        $viewInterface->shouldReceive('render')
            ->once()
            ->andReturn('rendered.plain');

        $view = m::mock(ViewFactory::class);
        $view->shouldReceive('make')->andReturn($viewInterface);

        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->send(['html' => 'foo', 'text' => 'bar'], ['data'], function (Message $message) {
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

    public function testToAllowsEmailAndName()
    {
        $view = $this->mockView();
        $mailer = new Mailer('array', $view, new ArrayTransport());

        $sentMessage = $mailer->to('taylor@hypervel.org', 'Taylor Otwell')->send(new TestMail());

        $recipients = $sentMessage->getEnvelope()->getRecipients();
        $this->assertCount(1, $recipients);
        $this->assertSame('taylor@hypervel.org', $recipients[0]->getAddress());
        $this->assertSame('Taylor Otwell', $recipients[0]->getName());
    }

    public function testGlobalFromIsRespectedOnAllMessages()
    {
        $view = $this->mockView();
        $mailer = new Mailer('array', $view, new ArrayTransport());
        $mailer->alwaysFrom('hello@hypervel.org');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('taylor@hypervel.org');
        });

        $this->assertSame('taylor@hypervel.org', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame('hello@hypervel.org', $sentMessage->getEnvelope()->getSender()->getAddress());
    }

    public function testGlobalReplyToIsRespectedOnAllMessages()
    {
        $view = $this->mockView();
        $mailer = new Mailer('array', $view, new ArrayTransport());
        $mailer->alwaysReplyTo('taylor@hypervel.org', 'Taylor Otwell');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('dries@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertSame('dries@hypervel.org', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('Reply-To: Taylor Otwell <taylor@hypervel.org>', $sentMessage->toString());
    }

    public function testGlobalToIsRespectedOnAllMessages()
    {
        $view = $this->mockView();
        $mailer = new Mailer('array', $view, new ArrayTransport());
        $mailer->alwaysTo('taylor@hypervel.org', 'Taylor Otwell');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message) {
            $message->from('hello@hypervel.org');
            $message->to('nuno@hypervel.org');
            $message->cc('dries@hypervel.org');
            $message->bcc('james@hypervel.org');
        });

        $recipients = collect($sentMessage->getEnvelope()->getRecipients())->map(function ($recipient) {
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

    public function testGlobalReturnPathIsRespectedOnAllMessages()
    {
        $view = $this->mockView();

        $mailer = new Mailer('array', $view, new ArrayTransport());
        $mailer->alwaysReturnPath('taylorotwell@gmail.com');

        $sentMessage = $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });

        $this->assertStringContainsString('Return-Path: <taylorotwell@gmail.com>', $sentMessage->toString());
    }

    public function testEventsAreDispatched()
    {
        $view = $this->mockView();

        $events = m::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch')->once()->with(m::type(MessageSending::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(MessageSent::class));

        $mailer = new Mailer('array', $view, new ArrayTransport(), $events);

        $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('taylor@hypervel.org')->from('hello@hypervel.org');
        });
    }

    public function testMacroable()
    {
        Mailer::macro('foo', function () {
            return 'bar';
        });

        $mailer = new Mailer('array', m::mock(ViewFactory::class), new ArrayTransport());

        $this->assertSame(
            'bar',
            $mailer->foo()
        );
    }

    protected function mockContainer(): Container
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => m::mock(ConfigInterface::class),
                ViewFactory::class => ViewFactory::class,
                EventDispatcherInterface::class => fn () => m::mock(EventDispatcherInterface::class),
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }

    protected function mockView()
    {
        $viewInterface = m::mock(ViewContract::class);
        $viewInterface->shouldReceive('render')
            ->andReturn('rendered.view');

        $view = m::mock(ViewFactory::class);
        $view->shouldReceive('make')->andReturn($viewInterface);

        return $view;
    }
}

class TestMail extends Mailable
{
    public function build()
    {
        return $this->view('view')
            ->from('hello@hypervel.org');
    }
}
