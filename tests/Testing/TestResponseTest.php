<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\TestResponseTest;

use Exception;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\View\View;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\Collection;
use Hypervel\Support\Json;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\AssertionFailedError;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\VarDumper\VarDumper;

class TestResponseTest extends TestCase
{
    public function testAssertViewHasModel(): void
    {
        $model = new TestModel(['id' => 1]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => $model],
        ]);

        $response->assertViewHas('foo', $model);
    }

    public function testAssertViewHasEloquentCollection(): void
    {
        $collection = new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
            new TestModel(['id' => 3]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $collection],
        ]);

        $response->assertViewHas('foos', $collection);
    }

    public function testAssertViewHasEloquentCollectionRespectsOrder(): void
    {
        $collection = new EloquentCollection([
            new TestModel(['id' => 3]),
            new TestModel(['id' => 2]),
            new TestModel(['id' => 1]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $collection],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', $collection->reverse()->values());
    }

    public function testAssertViewHasEloquentCollectionRespectsType(): void
    {
        $actual = new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $actual],
        ]);

        $expected = new EloquentCollection([
            new AnotherTestModel(['id' => 1]),
            new AnotherTestModel(['id' => 2]),
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', $expected);
    }

    public function testAssertViewHasEloquentCollectionRespectsSize(): void
    {
        $actual = new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $actual],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', $actual->concat([new TestModel(['id' => 3])]));
    }

    public function testAssertViewHasAcceptsTheExactKeylessModel(): void
    {
        $model = new TestModel;

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => $model],
        ]);

        $response->assertViewHas('foo', $model);
    }

    public function testAssertViewHasRejectsADifferentKeylessModel(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => new TestModel],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasAcceptsAModelWithTheSameStoredIdentity(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => new TestModel(['id' => 1])],
        ]);

        $response->assertViewHas('foo', new TestModel(['id' => 1]));
    }

    public function testAssertViewHasAcceptsACollectionContainingTheExactKeylessModels(): void
    {
        $collection = new EloquentCollection([new TestModel, new TestModel]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $collection],
        ]);

        $response->assertViewHas('foos', $collection);
    }

    public function testAssertViewHasRejectsACollectionContainingDifferentKeylessModels(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => new EloquentCollection([new TestModel, new TestModel])],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', new EloquentCollection([new TestModel, new TestModel]));
    }

    public function testAssertViewHasAcceptsACollectionWithTheSameStoredIdentities(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => [
                'foos' => new EloquentCollection([
                    new TestModel(['id' => 1]),
                    new TestModel(['id' => 2]),
                ]),
            ],
        ]);

        $response->assertViewHas('foos', new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]));
    }

    public function testAssertViewHasRejectsANonModelAsAnAssertionFailure(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => 'not a model'],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasReportsAMissingCollectionKeyAsAnAssertionFailure(): void
    {
        $actual = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            1 => new TestModel(['id' => 2]),
        ]);
        $expected = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            2 => new TestModel(['id' => 2]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $actual],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that the collection at [foos.2] matches the given collection.');

        $response->assertViewHas('foos', $expected);
    }

    public function testBinaryFileContentCanBeAssertedWithoutClassifyingTheResponseAsStreamed(): void
    {
        $response = TestResponse::fromBaseResponse(
            new BinaryFileResponse(__DIR__ . '/Fixtures/file.json')
        );

        $response->assertNotStreamed();
        $response->assertStreamedContent('{"foo":"bar"}');
        $response->assertStreamedJsonContent(['foo' => 'bar']);
    }

    public function testHeadStreamedContentDoesNotInvokeCallbackProducer(): void
    {
        $invocations = 0;
        $response = TestResponse::fromBaseResponse(
            new StreamedResponse(function () use (&$invocations): void {
                ++$invocations;

                echo 'content';
            }),
            Request::create('/', 'HEAD'),
        );

        $response->assertStreamedContent('');
        $this->assertSame(0, $invocations);
    }

    public function testHeadStreamedContentDoesNotInvokeIterableProducer(): void
    {
        $invocations = 0;
        $chunks = (function () use (&$invocations): iterable {
            ++$invocations;

            yield 'content';
        })();
        $response = TestResponse::fromBaseResponse(
            new IterableStreamedResponse($chunks),
            Request::create('/', 'HEAD'),
        );
        unset($chunks);

        $response->assertStreamedContent('');
        $this->assertSame(0, $invocations);
    }

    public function testRenderedTextAssertionsNormalizeMarkupEntitiesAndWhitespace(): void
    {
        $response = TestResponse::fromBaseResponse(new Response(
            "<main><p>Hello&nbsp;<strong>beautiful</strong>\u{2003}World</p><span>0</span></main>"
        ));

        $response
            ->assertSee(['beautiful', 'World'])
            ->assertSeeHtml(['<main>', '<strong>beautiful</strong>'])
            ->assertSeeHtmlInOrder(['<main>', '<span>0</span>'])
            ->assertSeeText(['Hello beautiful World', '0'])
            ->assertSeeTextInOrder(['Hello', 'beautiful', 'World', '0'])
            ->assertDontSee(['Goodbye', '<footer>'])
            ->assertDontSeeHtml(['<footer>', '<span>1</span>'])
            ->assertDontSeeText(['Goodbye World', '1']);
    }

    public function testRenderedTextAssertionsRetainMalformedUtf8Matching(): void
    {
        $response = TestResponse::fromBaseResponse(new Response("<p>Hello \xFF World</p>"));

        $response
            ->assertSeeText("Hello \xFF World", escape: false)
            ->assertDontSeeText("Goodbye \xFF World", escape: false);
    }

    public function testRenderedTextAssertionsRejectExpectedValuesWithoutVisibleText(): void
    {
        $response = TestResponse::fromBaseResponse(new Response('<p>Hello World</p>'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            'Failed asserting that the expected value "<strong></strong>" contains visible text.'
        );

        $response->assertSeeText('<strong></strong>', escape: false);
    }

    public function testBulkJsonPathAssertionsDelegateToSinglePathAssertions(): void
    {
        $response = TestResponse::fromBaseResponse(new Response(json_encode([
            'data' => [
                'id' => 1,
                'name' => 'Taylor',
                'roles' => ['admin', 'editor'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $response
            ->assertJsonPaths([
                'data.id' => 1,
                'data.name' => fn ($value) => $value === 'Taylor',
            ])
            ->assertJsonPathsCanonicalizing([
                'data.roles' => ['editor', 'admin'],
            ])
            ->assertJsonMissingPaths([
                'data.email',
                'meta',
            ]);
    }

    public function testBulkJsonPathAssertionsStopAtTheFailingPath(): void
    {
        $response = TestResponse::fromBaseResponse(new Response('{"data":{"id":1}}'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that 1 is identical to 2.');

        $response->assertJsonPaths([
            'data.id' => 2,
            'data.missing' => true,
        ]);
    }

    public function testSessionMissingInputAcceptsOneOrManyKeys(): void
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $store->put('_old_input', ['present' => 'value']);
        $response = new TestResponseWithSession(new Response, $store);

        $response
            ->assertSessionMissingInput('missing')
            ->assertSessionMissingInput(['missing', 'also-missing']);
    }

    public function testSessionMissingInputReportsTheUnexpectedKey(): void
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $store->put('_old_input', ['present' => 'value']);
        $response = new TestResponseWithSession(new Response, $store);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Session has unexpected key [present].');

        $response->assertSessionMissingInput('present');
    }

    public function testSessionHasAllReportsAllOrdinaryValueMismatches(): void
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $store->put('first', 'wrong-first');
        $store->put('second', 'wrong-second');
        $response = new TestResponseWithSession(new Response, $store);

        try {
            $response->assertSessionHasAll([
                'first' => 'expected-first',
                'second' => 'expected-second',
                'missing' => 'expected-missing',
            ]);
            $this->fail('The session assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $diff = $exception->getComparisonFailure()?->getDiff();

            $this->assertIsString($diff);
            $this->assertStringContainsString('wrong-first', $diff);
            $this->assertStringContainsString('wrong-second', $diff);
            $this->assertStringContainsString('expected-first', $diff);
            $this->assertStringContainsString('expected-second', $diff);
            $this->assertStringContainsString('expected-missing', $diff);
        }
    }

    public function testSessionHasAllRetainsKeyAndClosureAssertions(): void
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $store->put('present', true);
        $store->put('name', 'Taylor');
        $response = new TestResponseWithSession(new Response, $store);

        $response->assertSessionHasAll([
            'present',
            'name' => fn ($value) => $value === 'Taylor',
        ]);
    }

    public function testSessionHasAllTreatsKeyedNullAsPresentAndNotNull(): void
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $store->put('present', 'value');
        $response = new TestResponseWithSession(new Response, $store);

        $response->assertSessionHasAll(['present' => null]);
    }

    public function testSessionHasAllRejectsAnAbsentKeyExpectedAsNull(): void
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $response = new TestResponseWithSession(new Response, $store);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Session is missing expected key [missing].');

        $response->assertSessionHasAll(['missing' => null]);
    }

    public function testSessionHasAllRejectsAPresentNullValueExpectedAsNull(): void
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $store->put('present', null);
        $response = new TestResponseWithSession(new Response, $store);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Session is missing expected key [present].');

        $response->assertSessionHasAll(['present' => null]);
    }

    public function testDecodeResponseJsonAcceptsEveryJsonRootAndMemoizesTheWrapper(): void
    {
        foreach ([
            ['false', false],
            ['true', true],
            ['42', 42],
            ['"value"', 'value'],
            ['null', null],
            [" \t\n\rnull \t\n\r", null],
            ['[]', []],
            ['{"id":1}', ['id' => 1]],
        ] as [$content, $expected]) {
            $response = TestResponse::fromBaseResponse(new Response($content));
            $decoded = $response->decodeResponseJson();

            $this->assertSame($expected, $decoded->json());
            $this->assertSame($decoded, $response->decodeResponseJson());
        }
    }

    public function testDecodeResponseJsonAcceptsTheMaximumSupportedNestingDepth(): void
    {
        $value = 'leaf';

        for ($index = 1; $index < Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $response = TestResponse::fromBaseResponse(new Response(Json::encode(['nested' => $value])));

        $this->assertSame(['nested' => $value], $response->decodeResponseJson()->json());
    }

    public function testDecodeResponseJsonRejectsOneLevelOverTheMaximumNestingDepth(): void
    {
        $value = 'leaf';

        for ($index = 0; $index < Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $response = TestResponse::fromBaseResponse(new Response(
            json_encode(['nested' => $value], JSON_THROW_ON_ERROR, Json::MAXIMUM_NESTING_DEPTH + 1)
        ));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Invalid JSON was returned from the route.');

        $response->decodeResponseJson();
    }

    public function testDumpDecodesJsonAsObjectsAndPreservesInvalidBytes(): void
    {
        $dumped = [];
        VarDumper::setHandler(function (mixed $value) use (&$dumped): void {
            $dumped[] = $value;
        });

        try {
            TestResponse::fromBaseResponse(new Response('{"nested":{"value":1}}'))->dump();
            TestResponse::fromBaseResponse(new Response('{invalid'))->dump();
        } finally {
            VarDumper::setHandler(null);
        }

        $this->assertInstanceOf(stdClass::class, $dumped[0]);
        $this->assertInstanceOf(stdClass::class, $dumped[0]->nested);
        $this->assertSame('{invalid', $dumped[1]);
    }

    public function testDecodeResponseJsonRejectsNullLikeContentWithInvalidWhitespace(): void
    {
        foreach (["null\0", "\vnull"] as $content) {
            $response = TestResponse::fromBaseResponse(new Response($content));

            try {
                $response->decodeResponseJson();
                $this->fail('Invalid JSON was accepted.');
            } catch (AssertionFailedError $exception) {
                $this->assertStringContainsString('Invalid JSON was returned from the route.', $exception->getMessage());
            }
        }
    }

    public function testDecodeResponseJsonPreservesTheStoredResponseException(): void
    {
        $expected = new Exception('route failed');
        $response = TestResponse::fromBaseResponse((new Response('invalid'))->withException($expected));

        try {
            $response->decodeResponseJson();
            $this->fail('Invalid JSON was accepted.');
        } catch (Exception $exception) {
            $this->assertSame($expected, $exception);
        }
    }

    public function testDecodeResponseJsonAcceptsStreamedScalarContentAndMemoizesIt(): void
    {
        $invocations = 0;
        $response = TestResponse::fromBaseResponse(new StreamedResponse(function () use (&$invocations): void {
            ++$invocations;

            echo 'false';
        }));

        $decoded = $response->decodeResponseJson();

        $this->assertFalse($decoded->json());
        $this->assertSame($decoded, $response->decodeResponseJson());
        $this->assertSame(1, $invocations);
    }

    public function testAssertionContextPrefersTheLastLoggedThrowable(): void
    {
        $response = TestResponse::fromBaseResponse(new Response('content'));
        $response->exceptions->push(new Exception('logged failure'));

        try {
            $response->assertSee('missing');
            $this->fail('The response assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('The following exception occurred during the last request:', $exception->getMessage());
            $this->assertStringContainsString('logged failure', $exception->getMessage());
        }
    }

    public function testAssertionContextAcceptsLoggedStrings(): void
    {
        $response = TestResponse::fromBaseResponse(new Response('content'));
        $response->exceptions->push('logged string');

        try {
            $response->assertSee('missing');
            $this->fail('The response assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('The following exception occurred during the last request:', $exception->getMessage());
            $this->assertStringContainsString('logged string', $exception->getMessage());
        }
    }

    public function testEmptyLoggedStringFallsThroughToRedirectErrors(): void
    {
        $store = $this->storeWithErrors('redirect failure');
        $redirect = (new RedirectResponse('/'))->setSession($store);
        $response = TestResponse::fromBaseResponse($redirect);
        $response->exceptions->push('');

        try {
            $response->assertSee('missing');
            $this->fail('The response assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('redirect failure', $exception->getMessage());
            $this->assertStringNotContainsString(
                'The following exception occurred during the last request:',
                $exception->getMessage(),
            );
        }
    }

    public function testUnsupportedLoggedContextFallsThroughToRedirectErrors(): void
    {
        $store = $this->storeWithErrors('redirect failure');
        $redirect = (new RedirectResponse('/'))->setSession($store);
        $response = TestResponse::fromBaseResponse($redirect);
        $response->exceptions->push(['unsupported']);

        try {
            $response->assertSee('missing');
            $this->fail('The response assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('redirect failure', $exception->getMessage());
        }
    }

    public function testAssertionContextPrefersLoggedContextOverRedirectAndJsonErrors(): void
    {
        $store = $this->storeWithErrors('redirect failure');
        $redirect = (new RedirectResponse('/'))->setSession($store);
        $redirect->headers->set('Content-Type', 'application/json');
        $redirect->setContent('{"errors":{"field":["json failure"]}}');
        $response = TestResponse::fromBaseResponse($redirect);
        $response->exceptions->push('logged failure');

        try {
            $response->assertSee('missing');
            $this->fail('The response assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('logged failure', $exception->getMessage());
            $this->assertStringNotContainsString('redirect failure', $exception->getMessage());
            $this->assertStringNotContainsString('The following errors occurred during the last request:', $exception->getMessage());
        }
    }

    public function testUnsupportedLoggedContextFallsThroughToJsonErrors(): void
    {
        $response = TestResponse::fromBaseResponse(new Response(
            '{"errors":{"field":["json failure"]}}',
            200,
            ['Content-Type' => 'application/json'],
        ));
        $response->exceptions->push(new Collection(['unsupported']));

        try {
            $response->assertSee('missing');
            $this->fail('The response assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('json failure', $exception->getMessage());
        }
    }

    private function makeMockResponse(array $content): TestResponse
    {
        $response = new Response;
        $response->setContent(m::mock(View::class, $content));

        return TestResponse::fromBaseResponse($response);
    }

    /**
     * Create a session store with validation errors.
     */
    private function storeWithErrors(string $message): Store
    {
        $store = new Store('test-session', new ArraySessionHandler(1));
        $store->put('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'field' => [$message],
        ])));

        return $store;
    }
}

class TestResponseWithSession extends TestResponse
{
    /**
     * Create a test response with an explicit session store.
     */
    public function __construct(
        Response $response,
        protected SessionContract $testSession,
    ) {
        parent::__construct($response);
    }

    /**
     * Get the session store.
     */
    protected function session(): SessionContract
    {
        return $this->testSession;
    }
}

class TestModel extends Model
{
    protected array $guarded = [];
}

class AnotherTestModel extends Model
{
    protected array $guarded = [];
}
