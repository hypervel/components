<?php

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Contracts\View\View;
use Hypervel\Mail\Mailable;
use Hypervel\Contracts\Mail\Mailer as MailerContract;
use Hypervel\Mail\Mailables\Content;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Route;
use Hypervel\View\Factory;

class FooController
{
    public function index()
    {
        return view('index');
    }

    public function existing(): View
    {
        return view('users.index');
    }

    public function existingNested(): View
    {
        return view('emails.orders.shipped');
    }

    public function notExisting(): View
    {
        return view('foo');
    }
}

class FooMailable extends Mailable
{
    public function build(): self
    {
        return $this->markdown('emails.mailable.markdown');
    }

    public function bar(): self
    {
        $this->html('unused');

        return $this->view('emails.mailable.view');
    }

    public function content(): Content
    {
        $foo = new Content('emails.mailable.content.view',);
        $bar = new Content(markdown: 'emails.mailable.content.markdown');

        $foo
            ->text('emails.mailable.content.text')
            ->html('emails.mailable.content.html');

        return $bar;
    }
}

class FooMailMessage extends MailMessage
{
    public function build(): self
    {
        return $this->markdown('emails.mail-message.markdown');
    }

    public function bar(): self
    {
        return $this->view('emails.mail-message.view');
    }
}

function mailFacadeSend(): void
{
    Mail::send(data: [], view: 'emails.mail-send.send-static');

    Mail::to('mail@example.com')
        ->send(new FooMailable());
}

function mailerContractSend(MailerContract $mailer): void
{
    $mailer->send(data: [], view: 'emails.mail-send.mailer');
    $mailer->send('emails.mail-send.send');
}

function viewHelper(): View
{
    return view()->make('view-helper-make');
}

function viewFactory(Factory $factory): View
{
    return $factory->make('view-factory-make');
}

function viewStaticMake(): View
{
    return \Hypervel\Support\Facades\View::make('view-static-make');
}

function routeView(): void
{
    Route::view('/welcome', 'route-view');
}

function dummyTranslationView()
{
    return view('translations');
}

class SomeClass {
    function someView(): void
    {
        view('modals/' . __FUNCTION__);
    }
}

// Hypervel: views passed as reordered named arguments.
function namedArguments(Factory $factory): void
{
    view(data: [], view: 'named-arguments.helper');
    $factory->make(data: [], view: 'named-arguments.factory-make');
    \Hypervel\Support\Facades\View::make(data: [], view: 'named-arguments.facade-make');
    Route::view(view: 'named-arguments.route-view', uri: '/named-arguments');
}

class NamedArgumentsMailable extends Mailable
{
    public function build(): self
    {
        return $this->text(data: [], textView: 'named-arguments.mailable-text');
    }
}

class NamedArgumentsMailMessage extends MailMessage
{
    public function build(): self
    {
        return $this->view(data: [], view: 'named-arguments.mail-message-view');
    }
}
