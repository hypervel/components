<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head;

use Closure;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Head\CurrentHead;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\HeadData;
use Hypervel\Head\HeadManager;
use Hypervel\Head\Inertia\ShareHead;
use Hypervel\Head\Schema\Article;
use Hypervel\Head\Tags\MetaTags;
use Hypervel\Head\Tags\Schemas;
use Hypervel\Http\Request;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Support\Header;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    /**
     * Disable the external SSR server while exercising the rendering integration.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('inertia.ssr.enabled', false);
    }

    public function testContainerResolvesIndependentMutableBuildersAndSchemas(): void
    {
        $first = $this->app->make(HeadBuilder::class);
        $second = $this->app->make(HeadBuilder::class);
        $first->title('First');

        $this->assertNotSame($first, $second);
        $data = new ReflectionProperty(HeadBuilder::class, 'data');
        $this->assertTrue($data->getValue($second)->isEmpty());
        $this->assertNotSame($this->app->make(HeadData::class), $this->app->make(HeadData::class));

        $firstSchema = $this->app->make(Article::class)->headline('First');
        $secondSchema = $this->app->make(Article::class);
        $this->assertNotSame($firstSchema, $secondSchema);
        $this->assertSame(['@type' => 'Article'], $secondSchema->toArray());
    }

    public function testConcurrentTeamsHaveIndependentBladeAndInertiaMetadata(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Shared default'));
        Head::resolveDefaultsUsing(function (HeadBuilder $head): void {
            $team = RequestContext::get()->attributes->get('team');
            $head->title('Team ' . $team, suffix: ' - ' . $team)
                ->manifest('/' . $team . '.webmanifest')
                ->canonical();
        });
        Head::resolveErrorsUsing(function (HeadBuilder $head, int $status): void {
            $head->description(RequestContext::get()->attributes->get('team') . ':' . $status);
        });
        Head::resolveCanonicalOriginUsing(fn (): string => 'https://' . RequestContext::get()->attributes->get('team') . '.test');

        $middleware = new ShareHead($this->app->make(HeadManager::class));
        $render = fn (string $team, int $status): Closure => function () use ($team, $status, $middleware): array {
            $request = Request::create('http://alias.test/' . $team);
            $request->attributes->set('team', $team);
            $request->headers->set(Header::INERTIA, 'true');
            RequestContext::set($request);
            Head::title('Page ' . $team)->status($status);
            usleep(5000);

            $json = $middleware->handle($request, fn (Request $request): Response => Inertia::render('Team')->toResponse($request));
            $elements = json_decode($json->getContent(), true, flags: JSON_THROW_ON_ERROR)['props']['head'];
            $blade = Head::render()->toHtml();

            $request->headers->remove(Header::INERTIA);
            $document = Inertia::render('Team')->toResponse($request)->getContent();

            return [$blade, $elements, $document];
        };

        [$first, $second] = parallel([$render('first', 404), $render('second', 503)]);

        foreach ([['first', 404, $first], ['second', 503, $second]] as [$team, $status, $result]) {
            [$blade, $elements, $document] = $result;
            $this->assertStringContainsString('<title>Page ' . $team . ' - ' . $team . '</title>', $blade);
            $this->assertStringContainsString('<meta name="description" content="' . $team . ':' . $status . '">', $blade);
            $this->assertStringContainsString('href="https://' . $team . '.test/' . $team . '"', $blade);
            $this->assertStringContainsString('href="/' . $team . '.webmanifest"', $blade);
            $this->assertContains('<title data-inertia="title">Page ' . $team . ' - ' . $team . '</title>', $elements);
            $this->assertContains('<link data-inertia="link:manifest" rel="manifest" href="/' . $team . '.webmanifest">', $elements);
            $this->assertStringContainsString('<title data-inertia="title">Page ' . $team . ' - ' . $team . '</title>', $document);
        }

        [$subsequent] = parallel([function (): array {
            $request = Request::create('http://alias.test/third');
            $request->attributes->set('team', 'third');
            RequestContext::set($request);

            return Head::toArray();
        }]);
        $this->assertSame('Team third', $subsequent['title']);
        $this->assertNull($subsequent['description']);
    }

    public function testCopiedContextsHaveIndependentBuildersSchemasAndStatus(): void
    {
        $schema = (new Article)->headline('Parent');
        Head::title('Parent')->meta('source', 'parent')->schema($schema)->status(404);

        $copy = fn (string $name, int $status): Closure => function () use ($name, $status): array {
            $state = $this->app->make(CurrentHead::class);
            $inheritedStatus = $state->status();
            $state->data()->builder(MetaTags::class)->tag('source', $name);

            $schemas = $state->data()->get(Schemas::class);
            $stored = (new ReflectionProperty(Schemas::class, 'schemas'))->getValue($schemas);
            $copiedSchema = reset($stored);
            $identityMatches = array_key_first($stored) === spl_object_hash($copiedSchema);
            $copiedSchema->headline($name);
            $schemas->schema($copiedSchema);

            Head::title($name)->status($status);
            usleep(5000);

            return [Head::toArray(), $inheritedStatus, $state->status(), $identityMatches];
        };

        [$first, $second] = parallel([$copy('First', 500), $copy('Second', 403)], copyContext: true);

        foreach ([['First', 500, $first], ['Second', 403, $second]] as [$name, $status, $result]) {
            [$data, $inheritedStatus, $currentStatus, $identityMatches] = $result;
            $this->assertSame(404, $inheritedStatus);
            $this->assertSame($status, $currentStatus);
            $this->assertSame($name, $data['title']);
            $this->assertSame([['key' => 'source', 'content' => $name]], $data['meta']);
            $this->assertCount(1, $data['schemas']);
            $this->assertSame($name, $data['schemas'][0]['headline']);
            $this->assertTrue($identityMatches);
        }

        $parent = Head::toArray();
        $this->assertSame('Parent', $parent['title']);
        $this->assertSame([['key' => 'source', 'content' => 'parent']], $parent['meta']);
        $this->assertSame('Parent', $parent['schemas'][0]['headline']);
        $this->assertSame('Parent', $schema->toArray()['headline']);
        $this->assertSame(404, $this->app->make(CurrentHead::class)->status());
    }
}
