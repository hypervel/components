<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Completions\CompletionResponse;
use Hypervel\Mcp\Server\Contracts\Completable;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Methods\CompletionComplete;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Prompts\Argument;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Transport\FakeTransporter;
use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;

class CompletionMethodTestServer extends Server
{
    protected string $name = 'Completion Method Test Server';

    protected array $capabilities = [
        'completions' => [],
    ];

    protected array $prompts = [
        CompletionMethodTestPrompt::class,
    ];

    protected array $resources = [
        CompletionMethodTestResource::class,
    ];
}

class CompletionMethodTestPrompt extends Prompt implements Completable
{
    protected string $description = 'Test prompt';

    public array $lastContext = [];

    /**
     * Return the prompt arguments.
     */
    public function arguments(): array
    {
        return [new Argument('test', 'Test arg')];
    }

    /**
     * Complete the prompt argument.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        $this->lastContext = $context;

        return CompletionResponse::match(['test']);
    }

    /**
     * Return the prompt text.
     */
    public function handle(Request $request): Response
    {
        return Response::text('test');
    }
}

class CompletionMethodTestResource extends Resource implements Completable
{
    protected string $uri = 'file://test';

    protected string $mimeType = 'text/plain';

    /**
     * Return the resource description.
     */
    public function description(): string
    {
        return 'Test resource';
    }

    /**
     * Complete the resource argument.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return CompletionResponse::match(['resource-test']);
    }

    /**
     * Return the resource content.
     */
    public function handle(Request $request): Response
    {
        return Response::text('test');
    }
}

class NonCompletionPrompt extends Prompt
{
    protected string $description = 'Non-completion prompt';

    /**
     * Return the prompt text.
     */
    public function handle(Request $request): Response
    {
        return Response::text('test');
    }
}

class CompletionMethodTestResourceWithTemplate extends Resource implements Completable, HasUriTemplate
{
    protected string $mimeType = 'text/plain';

    protected string $description = 'Test resource template';

    /**
     * Return the resource URI template.
     */
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://users/{userId}');
    }

    /**
     * Complete the resource argument.
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return CompletionResponse::match(['template-test']);
    }

    /**
     * Return the resource content.
     */
    public function handle(Request $request): Response
    {
        return Response::text('test');
    }
}

class CompletionCompleteTest extends TestCase
{
    public function testThrowsAnExceptionWhenCompletionCapabilityIsNotDeclared(): void
    {
        $server = new class(new FakeTransporter) extends Server {
            protected string $name = 'Test';

            protected array $prompts = [CompletionMethodTestPrompt::class];
        };

        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'completion-method-test-prompt'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Server does not support completions capability');
        $method->handle($request, $context);
    }

    public function testThrowsAnExceptionWhenTheRefIsMissing(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing required parameters: ref and argument');
        $method->handle($request, $context);
    }

    public function testThrowsAnExceptionWhenAnArgumentIsMissing(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'test'],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing required parameters: ref and argument');
        $method->handle($request, $context);
    }

    public function testThrowsAnExceptionWhenTheArgumentNameIsMissing(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'completion-method-test-prompt'],
            'argument' => ['value' => 'test'],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing argument name');
        $method->handle($request, $context);
    }

    public function testThrowsExceptionForInvalidReferenceType(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'invalid/type', 'name' => 'test'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid reference type');
        $method->handle($request, $context);
    }

    public function testThrowsExceptionWhenPromptNameIsMissing(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing [name] parameter');
        $method->handle($request, $context);
    }

    public function testThrowsExceptionWhenPromptNotFound(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'non-existent'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Prompt [non-existent] not found');
        $method->handle($request, $context);
    }

    public function testThrowsExceptionWhenResourceUriIsMissing(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/resource'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing [uri] parameter');
        $method->handle($request, $context);
    }

    public function testThrowsExceptionWhenResourceNotFound(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file://non-existent'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Resource [file://non-existent] not found');
        $method->handle($request, $context);
    }

    public function testReturnEmptyArrayWhenPrimitiveDoesNotSupportCompletion(): void
    {
        $server = new class(new FakeTransporter) extends Server {
            protected string $name = 'Test';

            protected array $capabilities = ['completions' => []];

            protected array $prompts = [NonCompletionPrompt::class];
        };

        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'non-completion-prompt'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $context = $server->createContext();

        $response = $method->handle($request, $context);

        $this->assertArrayHasKey('result', $response->toArray());
        $this->assertArrayHasKey('completion', $response->toArray()['result']);
        $this->assertSame([], $response->toArray()['result']['completion']['values']);
    }

    public function testCompletesForPrompt(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'completion-method-test-prompt'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $response = $method->handle($request, $context);

        $this->assertArrayHasKey('result', $response->toArray());
        $this->assertArrayHasKey('completion', $response->toArray()['result']);
        $this->assertSame(['test'], $response->toArray()['result']['completion']['values']);
    }

    public function testCompletesForResource(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file://test'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $response = $method->handle($request, $context);

        $this->assertArrayHasKey('result', $response->toArray());
        $this->assertArrayHasKey('completion', $response->toArray()['result']);
        $this->assertSame(['resource-test'], $response->toArray()['result']['completion']['values']);
    }

    public function testFindsResourceByTemplateMatch(): void
    {
        $server = new class(new FakeTransporter) extends Server {
            protected string $name = 'Test';

            protected array $capabilities = ['completions' => []];

            protected array $resources = [CompletionMethodTestResourceWithTemplate::class];
        };

        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file://users/123'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $context = $server->createContext();

        $response = $method->handle($request, $context);

        $this->assertArrayHasKey('result', $response->toArray());
        $this->assertSame(['template-test'], $response->toArray()['result']['completion']['values']);
    }

    public function testFindsResourceByExactTemplateString(): void
    {
        $server = new class(new FakeTransporter) extends Server {
            protected string $name = 'Test';

            protected array $capabilities = ['completions' => []];

            protected array $resources = [CompletionMethodTestResourceWithTemplate::class];
        };

        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file://users/{userId}'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $context = $server->createContext();

        $response = $method->handle($request, $context);

        $this->assertArrayHasKey('result', $response->toArray());
        $this->assertSame(['template-test'], $response->toArray()['result']['completion']['values']);
    }

    public function testExtractsAndPassesContextArgumentsToCompletionMethod(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'completion-method-test-prompt'],
            'argument' => ['name' => 'test', 'value' => ''],
            'context' => [
                'arguments' => [
                    'arg1' => 'test-value',
                    'arg2' => 'another-value',
                ],
            ],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $response = $method->handle($request, $context);

        $this->assertArrayHasKey('result', $response->toArray());
        $this->assertSame(['test'], $response->toArray()['result']['completion']['values']);
        $this->assertSame(['arg1' => 'test-value', 'arg2' => 'another-value'], $this->app->make(CompletionMethodTestPrompt::class)->lastContext);
    }

    public function testPassesEmptyContextWhenContextIsNotProvided(): void
    {
        $method = new CompletionComplete;
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'completion-method-test-prompt'],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $server = new CompletionMethodTestServer(new FakeTransporter);
        $context = $server->createContext();

        $response = $method->handle($request, $context);

        $this->assertArrayHasKey('result', $response->toArray());
        $this->assertSame(['test'], $response->toArray()['result']['completion']['values']);
        $this->assertSame([], $this->app->make(CompletionMethodTestPrompt::class)->lastContext);
    }

    #[DataProvider('invalidParameters')]
    public function testRejectsMalformedParameters(array $overrides): void
    {
        $request = new JsonRpcRequest('1', 'completion/complete', array_replace([
            'ref' => ['type' => 'ref/prompt', 'name' => 'completion-method-test-prompt'],
            'argument' => ['name' => 'test', 'value' => ''],
        ], $overrides));

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32602);

        (new CompletionComplete)->handle($request, (new CompletionMethodTestServer(new FakeTransporter))->createContext());
    }

    /**
     * Return invalid completion parameter shapes.
     */
    public static function invalidParameters(): array
    {
        return [
            'reference scalar' => [['ref' => 'prompt']],
            'argument scalar' => [['argument' => 'test']],
            'prompt name' => [['ref' => ['type' => 'ref/prompt', 'name' => []]]],
            'resource URI' => [['ref' => ['type' => 'ref/resource', 'uri' => []]]],
            'argument name' => [['argument' => ['name' => []]]],
            'argument value' => [['argument' => ['name' => 'test', 'value' => []]]],
            'context scalar' => [['context' => 'invalid']],
            'context arguments' => [['context' => ['arguments' => 'invalid']]],
        ];
    }

    public function testCancellationIsNotConvertedToAnEmptyCompletion(): void
    {
        $exception = new CanceledException('Completion cancelled.');
        $prompt = new class($exception) extends CompletionMethodTestPrompt {
            /**
             * Capture the cancellation to raise during completion.
             */
            public function __construct(private CanceledException $exception)
            {
            }

            /**
             * Cancel the completion operation.
             */
            public function complete(string $argument, string $value, array $context): CompletionResponse
            {
                throw $this->exception;
            }
        };
        $this->app->instance(CompletionMethodTestPrompt::class, $prompt);
        $request = new JsonRpcRequest('1', 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => $prompt->name()],
            'argument' => ['name' => 'test', 'value' => ''],
        ]);

        $this->expectExceptionObject($exception);

        (new CompletionComplete)->handle($request, (new CompletionMethodTestServer(new FakeTransporter))->createContext());
    }
}
