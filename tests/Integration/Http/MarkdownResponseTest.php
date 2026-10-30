<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class MarkdownResponseTest extends TestCase
{
    public function testMarkdownResponse(): void
    {
        Route::get('/markdown', function (): Response {
            return response()->markdown("# Hello\n\nWorld");
        });

        $response = $this->get('/markdown');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
        $response->assertContent("# Hello\n\nWorld");
    }

    public function testMarkdownResponseWithStatusAndHeaders(): void
    {
        Route::get('/markdown', function (): Response {
            return response()->markdown('# Not Found', 404, [
                'Content-Type' => 'text/html',
                'X-Foo' => 'bar',
            ]);
        });

        $response = $this->get('/markdown');

        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
        $response->assertHeader('X-Foo', 'bar');
        $response->assertContent('# Not Found');
    }

    public function testMarkdownResponseCanBeNegotiated(): void
    {
        Route::get('/markdown', function (Request $request): Response {
            return $request->wantsMarkdown()
                ? response()->markdown('# Hello')
                : response('<h1>Hello</h1>');
        });

        $this->get('/markdown', ['Accept' => 'text/markdown'])
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertContent('# Hello');

        $this->get('/markdown', ['Accept' => 'text/html'])
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertContent('<h1>Hello</h1>');
    }
}
