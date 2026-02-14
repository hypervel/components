<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\HttpServer\Server as HttpServer;
use Hypervel\Server\Event;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\RequestWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * @internal
 * @coversNothing
 */
class RequestWatchersTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')
            ->set('telescope.watchers', [
                RequestWatcher::class => true,
            ]);
        $this->app->make('config')
            ->set('server.servers', [
                'http' => [
                    'name' => 'http',
                    'port' => 9501,
                    'callbacks' => [
                        Event::ON_REQUEST => [m::mock(HttpServer::class)],
                    ],
                ],
            ]);

        $this->startTelescope();
    }

    public function testRegisterEnableRequestEvents()
    {
        $this->assertTrue(
            $this->app->make('config')
                ->get('server.servers.http.options.enable_request_lifecycle', false)
        );
    }

    public function testRequestWatcherRegistersRequests()
    {
        $result = ['email' => 'albert@hypervel.org'];
        Route::get('/emails', fn () => $result);

        $this->get('/emails')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('/emails', $entry->content['uri']);
        $this->assertSame($result, $entry->content['response']);
    }

    public function testRequestWatcherRegisters404()
    {
        $this->get('/whatever');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(404, $entry->content['response_status']);
        $this->assertSame('/whatever', $entry->content['uri']);
    }

    public function testRequestWatcherHidesPassword()
    {
        Route::post('/auth', fn () => 'success');

        $this->post('/auth', [
            'email' => 'telescope@hypervel.org',
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('telescope@hypervel.org', $entry->content['payload']['email']);
        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame('********', $entry->content['payload']['password_confirmation']);
    }

    public function testRequestWatcherHidesAuthorization()
    {
        Route::post('/dashboard', fn () => 'success');

        $this->post('/dashboard', [], [
            'authorization' => 'Basic YWxhZGRpbjpvcGVuc2VzYW1l',
            'content-type' => 'application/json',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('application/json', $entry->content['headers']['content-type']);
        $this->assertSame('********', $entry->content['headers']['authorization']);
    }

    public function testRequestWatcherHidesPhpAuthPw()
    {
        Route::post('/dashboard', fn () => 'success');

        $this->post('/dashboard', [], [
            'php-auth-pw' => 'secret',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('********', $entry->content['headers']['php-auth-pw']);
    }

    public function testItStoresAndDisplaysArrayOfRequestAndResponseHeaders()
    {
        Route::post('/dashboard', function () {
            /* @phpstan-ignore-next-line */
            return Response::make('success')->withHeaders([
                'X-Foo' => ['third', 'fourth'],
            ]);
        });

        $this->post('/dashboard', [], [
            'X-Bar' => ['first', 'second'],
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('first, second', $entry->content['headers']['X-Bar']);
        $this->assertSame('third, fourth', $entry->content['response_headers']['X-Foo']);
    }

    #[RequiresPhpExtension('gd')]
    public function testRequestWatcherHandlesFileUploads()
    {
        $image = UploadedFile::fake()->image('avatar.jpg');

        $this->post('fake-upload-file-route', [
            'image' => $image,
        ]);

        $uploadedImage = $this->loadTelescopeEntries()->first()->content['payload']['image'];

        $this->assertSame($image->getClientOriginalName(), $uploadedImage['name']);

        $this->assertSame($image->getSize() / 1000 . 'KB', $uploadedImage['size']);
    }

    #[RequiresPhpExtension('gd')]
    public function testRequestWatcherHandlesUnlinkedFileUploads()
    {
        $image = UploadedFile::fake()->image('unlinked-image.jpg');

        unlink($image->getPathName());

        $this->post('fake-upload-file-route', [
            'unlinked-image' => $image,
        ]);

        $uploadedImage = $this->loadTelescopeEntries()->first()->content['payload']['unlinked-image'];

        $this->assertSame($image->getClientOriginalName(), $uploadedImage['name']);

        $this->assertSame('0', $uploadedImage['size']);
    }

    public function testRequestWatcherPlainTextResponse()
    {
        Route::get('/fake-plain-text', function () {
            return Response::make(
                'plain telescope response',
                200,
                ['Content-Type' => 'text/plain']
            );
        });

        $this->get('/fake-plain-text')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('plain telescope response', $entry->content['response']);
    }
}
