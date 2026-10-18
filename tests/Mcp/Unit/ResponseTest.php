<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit;

use Hypervel\Mcp\Enums\Role;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Attributes\Icon as IconAttribute;
use Hypervel\Mcp\Server\Content\Audio;
use Hypervel\Mcp\Server\Content\Blob;
use Hypervel\Mcp\Server\Content\Image;
use Hypervel\Mcp\Server\Content\Notification;
use Hypervel\Mcp\Server\Content\ResourceLink;
use Hypervel\Mcp\Server\Content\Text;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Mcp\Fixtures\AnnotatedResource;
use Hypervel\Tests\Mcp\Fixtures\DailyPlanResource;
use InvalidArgumentException;
use JsonException;

class ResponseTest extends TestCase
{
    protected array $createdFiles = [];

    protected function tearDown(): void
    {
        File::delete($this->createdFiles);

        parent::tearDown();
    }

    public function testCreatesANotificationResponse(): void
    {
        $response = Response::notification('test.method', ['key' => 'value']);

        $this->assertInstanceOf(Notification::class, $response->content());
        $this->assertTrue($response->isNotification());
        $this->assertFalse($response->isError());
        $this->assertSame(Role::User, $response->role());
    }

    public function testCreatesATextResponse(): void
    {
        $response = Response::text('Hello world');

        $this->assertInstanceOf(Text::class, $response->content());
        $this->assertFalse($response->isNotification());
        $this->assertFalse($response->isError());
        $this->assertSame(Role::User, $response->role());
    }

    public function testCreatesABlobResponse(): void
    {
        $response = Response::blob('binary content');

        $this->assertInstanceOf(Blob::class, $response->content());
        $this->assertFalse($response->isNotification());
        $this->assertFalse($response->isError());
        $this->assertSame(Role::User, $response->role());
    }

    public function testCreatesAnErrorResponse(): void
    {
        $response = Response::error('Something went wrong');

        $this->assertInstanceOf(Text::class, $response->content());
        $this->assertFalse($response->isNotification());
        $this->assertTrue($response->isError());
        $this->assertSame(Role::User, $response->role());
    }

    public function testCreatesAnImageResponse(): void
    {
        $response = Response::image('raw-bytes');

        $this->assertInstanceOf(Image::class, $response->content());
        $this->assertFalse($response->isNotification());
        $this->assertFalse($response->isError());
        $this->assertSame(Role::User, $response->role());
    }

    public function testCreatesAnImageResponseWithCustomMimeType(): void
    {
        $response = Response::image('raw-bytes', 'image/webp');

        $this->assertInstanceOf(Image::class, $response->content());
        $this->assertSame('image/webp', $response->content()->toArray()['mimeType']);
    }

    public function testCreatesAnAudioResponse(): void
    {
        $response = Response::audio('raw-bytes');

        $this->assertInstanceOf(Audio::class, $response->content());
        $this->assertFalse($response->isNotification());
        $this->assertFalse($response->isError());
        $this->assertSame(Role::User, $response->role());
    }

    public function testCreatesAnAudioResponseWithCustomMimeType(): void
    {
        $response = Response::audio('raw-bytes', 'audio/mp3');

        $this->assertInstanceOf(Audio::class, $response->content());
        $this->assertSame('audio/mp3', $response->content()->toArray()['mimeType']);
    }

    public function testCreatesImageResponseWithContentMeta(): void
    {
        $response = Response::image('raw-bytes')->withMeta(['source' => 'camera']);

        $this->assertInstanceOf(Image::class, $response->content());
        $this->assertArrayHasKey('_meta', $response->content()->toArray());
        $this->assertSame(['source' => 'camera'], $response->content()->toArray()['_meta']);
    }

    public function testCreatesAudioResponseWithContentMeta(): void
    {
        $response = Response::audio('raw-bytes')->withMeta(['duration' => '3.5s']);

        $this->assertInstanceOf(Audio::class, $response->content());
        $this->assertArrayHasKey('_meta', $response->content()->toArray());
        $this->assertSame(['duration' => '3.5s'], $response->content()->toArray()['_meta']);
    }

    public function testCanConvertResponseToAssistantRole(): void
    {
        $response = Response::text('Original message');
        $assistantResponse = $response->asAssistant();

        $this->assertInstanceOf(Text::class, $assistantResponse->content());
        $this->assertSame(Role::Assistant, $assistantResponse->role());
        $this->assertFalse($assistantResponse->isError());
    }

    public function testPreservesErrorStateWhenConvertingToAssistantRole(): void
    {
        $response = Response::error('Error message');
        $assistantResponse = $response->asAssistant();

        $this->assertInstanceOf(Text::class, $assistantResponse->content());
        $this->assertSame(Role::Assistant, $assistantResponse->role());
        $this->assertTrue($assistantResponse->isError());
    }

    public function testCreatesAJsonResponse(): void
    {
        $data = ['key' => 'value', 'number' => 123];
        $response = Response::json($data);

        $this->assertInstanceOf(Text::class, $response->content());
        $this->assertFalse($response->isNotification());
        $this->assertFalse($response->isError());
        $this->assertSame(Role::User, $response->role());

        $content = $response->content();
        $this->assertSame(json_encode($data, JSON_THROW_ON_ERROR), (string) $content);
    }

    public function testHandlesNestedArraysInJsonResponse(): void
    {
        $data = [
            'user' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'roles' => ['admin', 'developer'],
            ],
            'active' => true,
        ];
        $response = Response::json($data);

        $this->assertInstanceOf(Text::class, $response->content());

        $content = $response->content();
        $this->assertSame(json_encode($data, JSON_THROW_ON_ERROR), (string) $content);
    }

    public function testThrowsJsonExceptionForInvalidJsonData(): void
    {
        $data = ['invalid' => INF];

        $this->expectException(JsonException::class);
        Response::json($data);
    }

    public function testHandlesEmptyArrayInJsonResponse(): void
    {
        $data = [];
        $response = Response::json($data);

        $this->assertInstanceOf(Text::class, $response->content());

        $content = $response->content();
        $this->assertSame(json_encode($data, JSON_THROW_ON_ERROR), (string) $content);
    }

    public function testCreatesTextResponseWithContentMeta(): void
    {
        $response = Response::text('Hello')->withMeta(['author' => 'John']);

        $this->assertInstanceOf(Text::class, $response->content());
        $this->assertArrayHasKey('_meta', $response->content()->toArray());
        $this->assertSame(['author' => 'John'], $response->content()->toArray()['_meta']);
    }

    public function testCreatesBlobResponseWithContentMeta(): void
    {
        $response = Response::blob('binary')->withMeta(['encoding' => 'utf-8']);

        $this->assertInstanceOf(Blob::class, $response->content());
        $this->assertArrayHasKey('_meta', $response->content()->toArray());
        $this->assertSame(['encoding' => 'utf-8'], $response->content()->toArray()['_meta']);
    }

    public function testCreatesANotificationResponseWithContentMeta(): void
    {
        $response = Response::notification('test/event', ['data' => 'value'])->withMeta(['author' => 'system']);

        $this->assertInstanceOf(Notification::class, $response->content());
        $this->assertArrayHasKey('_meta', $response->content()->toArray()['params']);
        $this->assertSame(['author' => 'system'], $response->content()->toArray()['params']['_meta']);
    }

    public function testThrowsExceptionWhenArrayContainsANonResponseObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::make([
            Response::text('Valid'),
            'Invalid string',
        ]);
    }

    public function testThrowsExceptionWhenArrayContainsNestedResponseFactory(): void
    {
        $nestedFactory = Response::make(Response::text('Nested'));

        $this->expectException(InvalidArgumentException::class);
        Response::make([
            Response::text('First'),
            $nestedFactory,
            Response::text('Third'),
        ]);
    }

    public function testThrowsExceptionWhenAnArrayContainsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::make([
            Response::text('Valid'),
            null,
        ]);
    }

    public function testReadsHtmlContentFromARelativeFilePath(): void
    {
        $dir = resource_path();

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->createdFiles[] = $dir . '/test-response.html';
        file_put_contents($dir . '/test-response.html', '<html><body>From File</body></html>');

        $response = Response::html('test-response.html');

        $this->assertInstanceOf(Text::class, $response->content());
        $this->assertSame('<html><body>From File</body></html>', (string) $response->content());
    }

    public function testReadsHtmlContentFromAnAbsoluteFilePath(): void
    {
        $path = base_path('mcp-test-absolute.html');
        $this->createdFiles[] = $path;

        file_put_contents($path, '<div>Absolute</div>');

        $response = Response::html($path);

        $this->assertSame('<div>Absolute</div>', (string) $response->content());
    }

    public function testCreatesAResponseFromABladeView(): void
    {
        $viewDir = resource_path('views');

        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        $this->createdFiles[] = $viewDir . '/mcp-view-test.blade.php';
        file_put_contents($viewDir . '/mcp-view-test.blade.php', '<p>{{ $name }}</p>');

        $response = Response::view('mcp-view-test', ['name' => 'Test']);

        $this->assertInstanceOf(Text::class, $response->content());
        $this->assertStringContainsString('<p>Test</p>', (string) $response->content());
        $this->assertFalse($response->isError());
    }

    public function testThrowsExceptionForMissingHtmlFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found at path [/nonexistent/path/missing.html].');
        Response::html('/nonexistent/path/missing.html');
    }

    public function testCreatesCompactJsonResponse(): void
    {
        $data = ['key' => 'value', 'number' => 123];
        $response = Response::json($data);

        $this->assertInstanceOf(Text::class, $response->content());

        $content = (string) $response->content();
        $this->assertSame('{"key":"value","number":123}', $content);
        $this->assertStringNotContainsString("\n", $content);
    }

    public function testCreatesAResourceLinkFromAUriString(): void
    {
        $response = Response::resourceLink(
            'file:///data/report.json',
            'Monthly Report',
            'application/json',
            description: 'Generated sales report',
        );

        $this->assertEquals([
            'type' => 'resource_link',
            'uri' => 'file:///data/report.json',
            'name' => 'Monthly Report',
            'description' => 'Generated sales report',
            'mimeType' => 'application/json',
        ], $response->content()->toArray());
    }

    public function testRequiresANameWhenCreatingAResourceLinkFromAUriString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Resource link name is required when using a URI string.');
        Response::resourceLink('file:///data/report.json');
    }

    public function testCreatesAResourceLinkFromAResourceClassString(): void
    {
        $response = Response::resourceLink(DailyPlanResource::class);

        $payload = $response->content()->toArray();

        $this->assertSame('resource_link', $payload['type']);
        $this->assertSame((new DailyPlanResource)->uri(), $payload['uri']);
        $this->assertSame((new DailyPlanResource)->name(), $payload['name']);
        $this->assertSame((new DailyPlanResource)->mimeType(), $payload['mimeType']);
    }

    public function testCreatesAResourceLinkFromAResourceInstance(): void
    {
        $resource = new DailyPlanResource;
        $response = Response::resourceLink($resource);

        $this->assertSame($resource->uri(), $response->content()->toArray()['uri']);
    }

    public function testInheritsAnnotationsFromAResource(): void
    {
        $response = Response::resourceLink(AnnotatedResource::class);

        $this->assertEquals([
            'audience' => ['user'],
            'priority' => 0.7,
            'lastModified' => '2026-05-01T00:00:00Z',
        ], $response->content()->toArray()['annotations']);
    }

    public function testWrapsAPreBuiltResourceLinkInstance(): void
    {
        $link = new ResourceLink(
            'file:///data/report.json',
            'Monthly Report',
            annotations: [
                'audience' => ['user', 'assistant'],
                'priority' => 0.9,
                'lastModified' => '2026-05-07T12:00:00Z',
            ],
        );

        $response = Response::resourceLink($link);

        $this->assertSame($link, $response->content());
        $this->assertEquals([
            'type' => 'resource_link',
            'uri' => 'file:///data/report.json',
            'name' => 'Monthly Report',
            'annotations' => [
                'audience' => ['user', 'assistant'],
                'priority' => 0.9,
                'lastModified' => '2026-05-07T12:00:00Z',
            ],
        ], $response->content()->toArray());
    }

    public function testAllowsOverridingFieldsWhenGivenAResource(): void
    {
        $response = Response::resourceLink(
            DailyPlanResource::class,
            title: 'Custom Title',
            size: 4096,
        );

        $payload = $response->content()->toArray();

        $this->assertSame('Custom Title', $payload['title']);
        $this->assertSame(4096, $payload['size']);
    }

    public function testInheritsIconsFromAResourceWhenNoneAreProvided(): void
    {
        $resource = new class extends Resource {
            protected string $uri = 'file://resources/iconic';

            public function handle(): string
            {
                return 'content';
            }

            public function icons(): array
            {
                return [new Icon('https://example.com/resource.png', mimeType: 'image/png')];
            }
        };

        $response = Response::resourceLink($resource);

        $this->assertSame([
            ['src' => 'https://example.com/resource.png', 'mimeType' => 'image/png'],
        ], $response->content()->toArray()['icons']);
    }

    public function testInheritsIconAttributesFromAResourceWhenNoneAreProvided(): void
    {
        $response = Response::resourceLink(new ResponseResourceWithIconAttribute);

        $this->assertSame([
            ['src' => 'https://example.com/attribute-resource.png', 'mimeType' => 'image/png'],
        ], $response->content()->toArray()['icons']);
    }

    public function testLetsExplicitIconsOverrideTheResourceIcons(): void
    {
        $resource = new class extends Resource {
            protected string $uri = 'file://resources/iconic';

            public function handle(): string
            {
                return 'content';
            }

            public function icons(): array
            {
                return [new Icon('https://example.com/inherited.png')];
            }
        };

        $response = Response::resourceLink(
            $resource,
            icons: [new Icon('https://example.com/override.png', mimeType: 'image/png')],
        );

        $this->assertSame([
            ['src' => 'https://example.com/override.png', 'mimeType' => 'image/png'],
        ], $response->content()->toArray()['icons']);
    }

    public function testAttachesIconsToAResourceLinkBuiltFromAUriString(): void
    {
        $response = Response::resourceLink(
            'https://example.com/data.json',
            name: 'Dataset',
            icons: [new Icon('https://example.com/data.png', mimeType: 'image/png')],
        );

        $this->assertSame([
            ['src' => 'https://example.com/data.png', 'mimeType' => 'image/png'],
        ], $response->content()->toArray()['icons']);
    }
}

#[IconAttribute('https://example.com/attribute-resource.png', mimeType: 'image/png')]
class ResponseResourceWithIconAttribute extends Resource
{
    protected string $uri = 'file://resources/icon-attribute';

    public function handle(): string
    {
        return 'content';
    }
}
