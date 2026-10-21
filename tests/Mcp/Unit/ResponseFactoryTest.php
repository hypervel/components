<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Content\StructuredContentText;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ResponseFactoryTest extends TestCase
{
    public function testCreatesAFactoryWithASingleResponse(): void
    {
        $response = Response::text('Hello');
        $factory = new ResponseFactory($response);

        $this->assertInstanceOf(ResponseFactory::class, $factory);
        $this->assertCount(1, $factory->responses());
        $this->assertSame($response, $factory->responses()->first());
    }

    public function testCreatesAFactoryWithMultipleResponses(): void
    {
        $response1 = Response::text('First');
        $response2 = Response::text('Second');
        $factory = new ResponseFactory([4 => $response1, 8 => $response2]);

        $this->assertInstanceOf(ResponseFactory::class, $factory);
        $this->assertCount(2, $factory->responses());
        $this->assertSame($response1, $factory->responses()->first());
        $this->assertSame($response2, $factory->responses()->last());
        $this->assertSame([$response1, $response2], $factory->responses()->all());
    }

    public function testEmptyStructuredContentIsAnObjectOnTheWire(): void
    {
        $factory = Response::make(Response::text('No fields'))->withStructuredContent([]);

        $this->assertSame('{"structuredContent":{}}', json_encode($factory->mergeStructuredContent([]), JSON_THROW_ON_ERROR));
    }

    public function testSupportsFluentWithMetaForResultLevelMetadata(): void
    {
        $factory = (new ResponseFactory(Response::text('Hello')))
            ->withMeta(['key' => 'value']);

        $this->assertSame(['key' => 'value'], $factory->getMeta());
    }

    public function testSupportsWithMetaWithKeyValueSignature(): void
    {
        $factory = (new ResponseFactory(Response::text('Hello')))
            ->withMeta('key1', 'value1')
            ->withMeta('key2', 'value2');

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $factory->getMeta());
    }

    public function testMergesMultipleWithMetaCalls(): void
    {
        $factory = (new ResponseFactory(Response::text('Hello')))
            ->withMeta(['key1' => 'value1'])
            ->withMeta(['key2' => 'value1'])
            ->withMeta(['key2' => 'value2']);

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $factory->getMeta());
    }

    public function testSupportsConditionableTrait(): void
    {
        $factory = (new ResponseFactory(Response::text('Hello')))
            ->when(true, fn (ResponseFactory $factory): ResponseFactory => $factory->withMeta(['conditional' => 'yes']));

        $this->assertSame(['conditional' => 'yes'], $factory->getMeta());
    }

    public function testSupportsUnlessFromConditionableTrait(): void
    {
        $factory = (new ResponseFactory(Response::text('Hello')))
            ->unless(false, fn (ResponseFactory $factory): ResponseFactory => $factory->withMeta(['unless' => 'applied']));

        $this->assertSame(['unless' => 'applied'], $factory->getMeta());
    }

    public function testSeparatesContentLevelMetaFromResultLevelMeta(): void
    {
        $response = Response::text('Hello')->withMeta(['content_meta' => 'content_value']);
        $factory = (new ResponseFactory($response))
            ->withMeta(['result_meta' => 'result_value']);

        $this->assertSame(['result_meta' => 'result_value'], $factory->getMeta());
        $this->assertSame($response, $factory->responses()->first());
    }

    public function testCreatesAStructuredContentResponse(): void
    {
        $factory = Response::structured(['result' => 'The result of the tool.']);

        $this->assertInstanceOf(ResponseFactory::class, $factory);
        $this->assertSame(['result' => 'The result of the tool.'], $factory->getStructuredContent());
        $this->assertCount(1, $factory->responses());

        $textResponse = $factory->responses()->first();
        $this->assertInstanceOf(StructuredContentText::class, $textResponse->content());
        $this->assertStringContainsString('"result":"The result of the tool."', $textResponse->content()->toArray()['text']);
    }

    public function testCreatesAStructuredContentResponseWithMeta(): void
    {
        $factory = Response::structured([
            'entry' => 'The result of the tool.',
        ])->withMeta(['x' => 'y']);

        $this->assertInstanceOf(ResponseFactory::class, $factory);
        $this->assertSame(['entry' => 'The result of the tool.'], $factory->getStructuredContent());
        $this->assertSame(['x' => 'y'], $factory->getMeta());
        $this->assertCount(1, $factory->responses());
    }

    public function testAddsStructuredContentToExistingResponseFactory(): void
    {
        $factory = Response::make([
            Response::text('result is this'),
        ])->withStructuredContent(['result' => 'result is this']);

        $this->assertInstanceOf(ResponseFactory::class, $factory);
        $this->assertSame(['result' => 'result is this'], $factory->getStructuredContent());
        $this->assertCount(1, $factory->responses());

        $textResponse = $factory->responses()->first();
        $this->assertSame('result is this', $textResponse->content()->toArray()['text']);
    }

    public function testAddsStructuredContentWithMetaToResponseFactory(): void
    {
        $factory = Response::make([
            Response::text('result is this')->withMeta(['x' => 'y']),
        ])->withStructuredContent(['result' => 'result is this']);

        $this->assertInstanceOf(ResponseFactory::class, $factory);
        $this->assertSame(['result' => 'result is this'], $factory->getStructuredContent());
        $this->assertCount(1, $factory->responses());
    }

    public function testMergesMultipleWithStructuredContentCalls(): void
    {
        $factory = (new ResponseFactory(Response::text('Hello')))
            ->withStructuredContent(['key1' => 'value1'])
            ->withStructuredContent(['key2' => 'value1'])
            ->withStructuredContent(['key2' => 'value2']);

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $factory->getStructuredContent());
    }

    public function testThrowsExceptionWhenStructuredResultIsWrappedInResponseFactory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::make([
            Response::structured(['result' => 'The result of the tool.']),
        ]);
    }
}
