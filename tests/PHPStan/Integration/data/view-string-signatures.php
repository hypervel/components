<?php

namespace ViewStringSignatures;

use Hypervel\Contracts\View\Factory;
use Hypervel\Foundation\Testing\Concerns\InteractsWithViews;
use Hypervel\Mail\Mailable;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\View;
use Hypervel\Testing\TestResponse;

function factoryMake(Factory $factory): void
{
    $factory->make('view-factory-make');
    $factory->make('view-factory-make-does-not-exist');
}

function routerView(Router $router): void
{
    $router->view('/uri', 'route-view');
    $router->view('/uri', 'route-view-does-not-exist');
}

function mailMessage(MailMessage $message): void
{
    $message->view('emails.mail-message.view');
    $message->view('emails.mail-message.view-does-not-exist');
    $message->markdown('emails.mail-message.markdown');
    $message->markdown('emails.mail-message.markdown-does-not-exist');
}

/** @param TestResponse<\Hypervel\Http\Response> $response */
function testResponseAssertion(TestResponse $response): void
{
    $response->assertViewIs('home');
    $response->assertViewIs('home-does-not-exist');
}

class UsesInteractsWithViews
{
    use InteractsWithViews;

    public function good(): void
    {
        $this->view('home');
    }

    public function bad(): void
    {
        $this->view('home-does-not-exist');
    }
}

function viewFacadeMake(): void
{
    View::make('view-factory-make');
    View::make('view-factory-make-does-not-exist');
}

function routeFacadeView(): void
{
    Route::view('/uri', 'route-view');
    Route::view('/uri', 'route-view-does-not-exist');
}

function mailMessageText(MailMessage $message): void
{
    $message->text('emails.mail-message.view');
    $message->text('emails.mail-message.text-does-not-exist');
}

function mailableText(Mailable $mailable): void
{
    $mailable->text('emails.mailable.view');
    $mailable->text('emails.mailable.text-does-not-exist');
}
