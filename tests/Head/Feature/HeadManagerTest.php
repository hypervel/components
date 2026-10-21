<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature;

use Hypervel\Context\CoroutineContext;
use Hypervel\Head\ErrorPages;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\HeadManager;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\HtmlString;
use Hypervel\Tests\Head\TestCase;

class HeadManagerTest extends TestCase
{
    public function testRendersTheHeadAsAnHtmlString(): void
    {
        $this->assertInstanceOf(HtmlString::class, Head::render());
    }

    public function testDefinitionsStayEagerAndRuntimeDefaultsAreLazyAndFresh(): void
    {
        $eagerCalls = 0;
        $runtimeCalls = 0;

        Head::defaults(function (HeadBuilder $head) use (&$eagerCalls): void {
            ++$eagerCalls;
            $head->title('Static')->description('Static description');
        });

        Head::resolveDefaultsUsing(function (HeadBuilder $head) use (&$runtimeCalls): void {
            ++$runtimeCalls;
            $site = CoroutineContext::get('head.test.site');
            $head->title($site);

            if ($site === 'First') {
                $head->description('First description');
            }
        });

        $this->assertSame(1, $eagerCalls);
        $this->assertSame(0, $runtimeCalls);

        CoroutineContext::set('head.test.site', 'First');
        $first = Head::toArray();
        $this->assertSame('First', $first['title']);
        $this->assertSame('First description', $first['description']);

        CoroutineContext::set('head.test.site', 'Second');
        $second = Head::toArray();
        $this->assertSame('Second', $second['title']);
        $this->assertSame('Static description', $second['description']);
        $this->assertSame(1, $eagerCalls);
        $this->assertSame(2, $runtimeCalls);
    }

    public function testRuntimeDefaultsCanBeReplacedAndCleared(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Static'));

        $manager = Head::resolveDefaultsUsing(fn (HeadBuilder $head): HeadBuilder => $head->title('First'));
        $this->assertSame($this->app->make(HeadManager::class), $manager);
        $this->assertSame('First', Head::toArray()['title']);

        Head::resolveDefaultsUsing(fn (HeadBuilder $head): HeadBuilder => $head->title('Second'));
        $this->assertSame('Second', Head::toArray()['title']);

        Head::resolveDefaultsUsing(null);
        $this->assertSame('Static', Head::toArray()['title']);
    }

    public function testRuntimeLayersPreserveRoutePageAndErrorPrecedence(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head
            ->title('Static', suffix: ' - Static')
            ->description('Static description'));
        Head::resolveDefaultsUsing(fn (HeadBuilder $head): HeadBuilder => $head
            ->title('Site', suffix: ' - Site')
            ->description('Runtime description'));
        Head::errors(function (ErrorPages $errors): void {
            $errors->defaults(robots: 'noindex');
            $errors->status(404, title: 'Static error', description: 'Static error description');
        });
        Head::resolveErrorsUsing(fn (HeadBuilder $head, int $status): HeadBuilder => $head->title('Runtime error ' . $status));

        Route::withHead(title: 'Group', description: 'Group description')->group(function (): void {
            Route::get('/head-precedence', function (): array {
                $route = Head::toArray();
                Head::title('Page');

                return [
                    'route' => $route,
                    'page' => Head::toArray(),
                    'error' => Head::toArray(404),
                ];
            })->withHead(title: 'Route');
        });

        $this->getJson('/head-precedence')
            ->assertOk()
            ->assertJsonPath('route.title', 'Route - Site')
            ->assertJsonPath('route.description', 'Group description')
            ->assertJsonPath('page.title', 'Page - Site')
            ->assertJsonPath('error.title', 'Runtime error 404 - Site')
            ->assertJsonPath('error.description', 'Static error description')
            ->assertJsonPath('error.robots', 'noindex');
    }

    public function testRuntimeErrorsUseTheEffectiveStatusEvenWithoutAStaticDefinition(): void
    {
        $statuses = [];
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Default'));
        Head::errors(fn (ErrorPages $errors): ErrorPages => $errors->status(404, title: 'Missing'));
        Head::resolveErrorsUsing(function (HeadBuilder $head, int $status) use (&$statuses): void {
            $statuses[] = $status;
            $head->description('Status ' . $status);
        });

        $this->assertNull(Head::toArray()['description']);
        $this->assertSame([], $statuses);

        Head::status(404);
        $explicit = Head::toArray(201);
        $this->assertSame('Default', $explicit['title']);
        $this->assertSame('Status 201', $explicit['description']);

        $current = Head::toArray();
        $this->assertSame('Missing', $current['title']);
        $this->assertSame('Status 404', $current['description']);
        $this->assertSame([201, 404], $statuses);
    }

    public function testRuntimeErrorsCanBeReplacedAndCleared(): void
    {
        Head::errors(fn (ErrorPages $errors): ErrorPages => $errors->status(404, title: 'Static'));
        Head::resolveErrorsUsing(fn (HeadBuilder $head, int $status): HeadBuilder => $head->title('First ' . $status));
        $this->assertSame('First 404', Head::toArray(404)['title']);

        Head::resolveErrorsUsing(fn (HeadBuilder $head, int $status): HeadBuilder => $head->title('Second ' . $status));
        $this->assertSame('Second 404', Head::toArray(404)['title']);

        Head::resolveErrorsUsing(null);
        $this->assertSame('Static', Head::toArray(404)['title']);
    }

    public function testFlushClearsPageDataAndStatusWhileKeepingBootDefaults(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Static'));
        Head::resolveDefaultsUsing(fn (HeadBuilder $head): HeadBuilder => $head->description('Runtime default'));
        Head::errors(fn (ErrorPages $errors): ErrorPages => $errors->status(404, title: 'Missing'));
        Head::title('Page')->status(404);
        $this->assertSame('Missing', Head::toArray()['title']);

        Head::flush();
        $resolved = Head::toArray();
        $this->assertSame('Static', $resolved['title']);
        $this->assertSame('Runtime default', $resolved['description']);
    }
}
