<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Data\Pipe;
use Hypervel\Saloon\Enums\PipeOrder;
use Hypervel\Saloon\Exceptions\DuplicatePipeNameException;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\MiddlewarePipeline;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Pipeline;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use WeakReference;

class MiddlewarePipelineTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testRequestMiddlewareCanAddAPipe(): void
    {
        $pipeline = new MiddlewarePipeline;

        $pipeline
            ->onRequest(function (PendingRequest $request): void {
                $request->withHeader('X-Pipe-One', 'Yee-Haw');
            })
            ->onRequest(function (PendingRequest $request): void {
                $request->withHeader('X-Pipe-Two', 'Howdy');
            });

        $pendingRequest = (new TestConnector)->createPendingRequest(new UserRequest);
        $pipeline->executeRequestPipeline($pendingRequest);

        $this->assertSame('Yee-Haw', $pendingRequest->headers()['X-Pipe-One']);
        $this->assertSame('Howdy', $pendingRequest->headers()['X-Pipe-Two']);
    }

    public function testRequestMiddlewareCanAddANamedPipe(): void
    {
        $pipeline = new MiddlewarePipeline;

        $pipeline->onRequest(function (PendingRequest $request): void {
            $request->withHeader('X-Pipe-One', 'Yee-Haw');
        }, 'YeeHawPipe');

        $pipe = $pipeline->requestPipeline()->pipes()[0];

        $this->assertInstanceOf(Pipe::class, $pipe);
        $this->assertSame('YeeHawPipe', $pipe->name);
        $this->assertNull($pipe->order);

        $pendingRequest = (new TestConnector)->createPendingRequest(new UserRequest);
        $pipeline->executeRequestPipeline($pendingRequest);

        $this->assertSame('Yee-Haw', $pendingRequest->headers()['X-Pipe-One']);
    }

    public function testRequestMiddlewareNamedPipeMustBeUnique(): void
    {
        $pipeline = new MiddlewarePipeline;

        $pipeline->onRequest(
            callable: function (PendingRequest $request): void {
                $request->withHeader('X-Pipe-One', 'Yee-Haw');
            },
            name: 'YeeHawPipe',
        );

        $this->expectException(DuplicatePipeNameException::class);
        $this->expectExceptionMessage('The "YeeHawPipe" pipe already exists on the pipeline');

        $pipeline->onRequest(
            callable: function (PendingRequest $request): void {
                $request->withHeader('X-Pipe-One', 'Yee-Haw');
            },
            name: 'YeeHawPipe',
        );
    }

    // Upstream passes a returned pending request to the next pipe. The manager always sends the pending request it
    // created, as upstream's own send path does, so a returned pending request is ignored.
    public function testRequestMiddlewareIgnoresAReturnedPendingRequest(): void
    {
        $pipeline = new MiddlewarePipeline;
        $connector = new TestConnector;
        $errorRequest = $connector->createPendingRequest(new ErrorRequest);
        $nextRequest = null;

        $pipeline
            ->onRequest(function (PendingRequest $request) use ($errorRequest): PendingRequest {
                $request->withHeader('X-Pipe-One', 'Yee-Haw');

                return $errorRequest;
            })
            ->onRequest(function (PendingRequest $request) use (&$nextRequest): void {
                $nextRequest = $request;
            });

        $pendingRequest = $connector->createPendingRequest(new UserRequest);
        $pipeline->executeRequestPipeline($pendingRequest);

        $this->assertSame($pendingRequest, $nextRequest);
        $this->assertSame('Yee-Haw', $pendingRequest->headers()['X-Pipe-One']);
    }

    public function testRequestMiddlewareRunsInOrderOfPipes(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onRequest(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onRequest(function () use (&$names): void {
                $names[] = 'Taylor';
            });

        $pipeline->executeRequestPipeline((new TestConnector)->createPendingRequest(new UserRequest));

        $this->assertSame(['Sam', 'Taylor'], $names);
    }

    public function testRequestMiddlewarePipeCanBeAddedToTheTopOfThePipeline(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onRequest(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onRequest(function () use (&$names): void {
                $names[] = 'Taylor';
            }, order: PipeOrder::FIRST)
            ->onRequest(function () use (&$names): void {
                $names[] = 'Andrew';
            });

        $pipeline->executeRequestPipeline((new TestConnector)->createPendingRequest(new UserRequest));

        $this->assertSame(['Taylor', 'Sam', 'Andrew'], $names);
    }

    public function testRequestMiddlewarePipeCanBeAddedToTheBottomOfThePipeline(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onRequest(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onRequest(function () use (&$names): void {
                $names[] = 'Taylor';
            }, order: PipeOrder::LAST)
            ->onRequest(function () use (&$names): void {
                $names[] = 'Andrew';
            });

        $pipeline->executeRequestPipeline((new TestConnector)->createPendingRequest(new UserRequest));

        $this->assertSame(['Sam', 'Andrew', 'Taylor'], $names);
    }

    public function testResponseMiddlewareCanAddANamedPipe(): void
    {
        $pipeline = new MiddlewarePipeline;
        $count = 0;

        $pipeline->onResponse(function () use (&$count): void {
            ++$count;
        }, 'ResponsePipe');

        $pipe = $pipeline->responsePipeline()->pipes()[0];

        $this->assertInstanceOf(Pipe::class, $pipe);
        $this->assertSame('ResponsePipe', $pipe->name);
        $this->assertNull($pipe->order);

        $pipeline->executeResponsePipeline($this->response());

        $this->assertSame(1, $count);
    }

    public function testResponseMiddlewareNamedPipeMustBeUnique(): void
    {
        $pipeline = new MiddlewarePipeline;

        $pipeline->onResponse(function (): void {
        }, 'ResponsePipe');

        $this->expectException(DuplicatePipeNameException::class);
        $this->expectExceptionMessage('The "ResponsePipe" pipe already exists on the pipeline');

        $pipeline->onResponse(function (): void {
        }, 'ResponsePipe');
    }

    public function testResponseMiddlewareCanAddAPipe(): void
    {
        $pipeline = new MiddlewarePipeline;
        $count = 0;

        $pipeline
            ->onResponse(function (Response $response) use (&$count): void {
                ++$count;
            })
            ->onResponse(function (Response $response) use (&$count): void {
                ++$count;
            });

        $response = $this->response();

        $this->assertSame($response, $pipeline->executeResponsePipeline($response));
        $this->assertSame(2, $count);
    }

    public function testResponseMiddlewareUsesAReturnedResponseInTheNextStep(): void
    {
        $mockClient = new MockClient([
            ErrorRequest::class => MockResponse::make(['error' => 'Server Error'], 500),
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
        ]);
        $connector = new TestConnector;
        $pipeline = new MiddlewarePipeline;

        $errorResponse = $connector->send(new ErrorRequest, $mockClient);

        $pipeline->onResponse(fn (): Response => $errorResponse);

        $response = $pipeline->executeResponsePipeline($connector->send(new UserRequest, $mockClient));

        $this->assertSame($errorResponse, $response);
    }

    public function testResponseMiddlewareRunsInOrderOfPipes(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onResponse(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onResponse(function () use (&$names): void {
                $names[] = 'Taylor';
            });

        $pipeline->executeResponsePipeline($this->response());

        $this->assertSame(['Sam', 'Taylor'], $names);
    }

    public function testResponseMiddlewarePipeCanBeAddedToTheTopOfThePipeline(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onResponse(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onResponse(function () use (&$names): void {
                $names[] = 'Taylor';
            }, order: PipeOrder::FIRST)
            ->onResponse(function () use (&$names): void {
                $names[] = 'Andrew';
            });

        $pipeline->executeResponsePipeline($this->response());

        $this->assertSame(['Taylor', 'Sam', 'Andrew'], $names);
    }

    public function testResponseMiddlewarePipeCanBeAddedToTheBottomOfThePipeline(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onResponse(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onResponse(function () use (&$names): void {
                $names[] = 'Taylor';
            }, order: PipeOrder::LAST)
            ->onResponse(function () use (&$names): void {
                $names[] = 'Andrew';
            });

        $pipeline->executeResponsePipeline($this->response());

        $this->assertSame(['Sam', 'Andrew', 'Taylor'], $names);
    }

    // Upstream sends to an unresolvable host and asserts inside a catch block, which also passes when nothing is
    // thrown. The fatal pipeline only needs a fatal exception, so these cases build one directly.
    public function testFatalMiddlewareCanAddAPipe(): void
    {
        $pipeline = new MiddlewarePipeline;
        $exception = $this->fatalException();
        $received = [];

        $pipeline
            ->onFatalException(function (FatalRequestException $exception) use (&$received): void {
                $received[] = $exception;
            })
            ->onFatalException(function (FatalRequestException $exception) use (&$received): void {
                $received[] = $exception;
            });

        $pipeline->executeFatalPipeline($exception);

        $this->assertSame([$exception, $exception], $received);
    }

    public function testFatalMiddlewareCanAddANamedPipe(): void
    {
        $pipeline = new MiddlewarePipeline;
        $count = 0;

        $pipeline->onFatalException(function () use (&$count): void {
            ++$count;
        }, 'FatalPipe');

        $pipe = $pipeline->fatalPipeline()->pipes()[0];

        $this->assertInstanceOf(Pipe::class, $pipe);
        $this->assertSame('FatalPipe', $pipe->name);
        $this->assertNull($pipe->order);

        $pipeline->executeFatalPipeline($this->fatalException());

        $this->assertSame(1, $count);
    }

    public function testFatalMiddlewareNamedPipeMustBeUnique(): void
    {
        $pipeline = new MiddlewarePipeline;

        $pipeline->onFatalException(
            callable: function (): void {
            },
            name: 'YeeHawPipe',
        );

        $this->expectException(DuplicatePipeNameException::class);
        $this->expectExceptionMessage('The "YeeHawPipe" pipe already exists on the pipeline');

        $pipeline->onFatalException(
            callable: function (): void {
            },
            name: 'YeeHawPipe',
        );
    }

    public function testFatalMiddlewareRunsInOrderOfPipes(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Taylor';
            });

        $pipeline->executeFatalPipeline($this->fatalException());

        $this->assertSame(['Sam', 'Taylor'], $names);
    }

    public function testFatalMiddlewarePipeCanBeAddedToTheTopOfThePipeline(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Taylor';
            }, order: PipeOrder::FIRST)
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Andrew';
            });

        $pipeline->executeFatalPipeline($this->fatalException());

        $this->assertSame(['Taylor', 'Sam', 'Andrew'], $names);
    }

    public function testFatalMiddlewarePipeCanBeAddedToTheBottomOfThePipeline(): void
    {
        $pipeline = new MiddlewarePipeline;
        $names = [];

        $pipeline
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Sam';
            })
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Taylor';
            }, order: PipeOrder::LAST)
            ->onFatalException(function () use (&$names): void {
                $names[] = 'Andrew';
            });

        $pipeline->executeFatalPipeline($this->fatalException());

        $this->assertSame(['Sam', 'Andrew', 'Taylor'], $names);
    }

    public function testYouCanMergeAMiddlewarePipelineTogether(): void
    {
        $pipelineA = new MiddlewarePipeline;
        $pipelineB = new MiddlewarePipeline;

        $pipelineA
            ->onRequest(function (PendingRequest $request): void {
                $request->withHeader('X-Pipe-One', 'Yee-Haw');
            })
            ->onRequest(function (PendingRequest $request): void {
                $request->withHeader('X-Pipe-One', 'Howdy');
            })
            ->onResponse(fn (Response $response): Response => $response->throw(), 'response');

        $this->assertEmpty($pipelineB->requestPipeline()->pipes());
        $this->assertEmpty($pipelineB->responsePipeline()->pipes());

        $pipelineB->merge($pipelineA);

        $this->assertCount(2, $pipelineB->requestPipeline()->pipes());
        $this->assertCount(1, $pipelineB->responsePipeline()->pipes());

        // Merging rebuilds the pipes, so compare what each one holds.
        $describe = static fn (Pipe $pipe): array => [$pipe->callable, $pipe->name, $pipe->order];

        $this->assertSame(
            array_map($describe, $pipelineA->requestPipeline()->pipes()),
            array_map($describe, $pipelineB->requestPipeline()->pipes()),
        );
        $this->assertSame(
            array_map($describe, $pipelineA->responsePipeline()->pipes()),
            array_map($describe, $pipelineB->responsePipeline()->pipes()),
        );
    }

    public function testWhenMergingAMiddlewarePipelineTogetherIfTwoPipelinesExistWithTheSamePipeItThrowsAnException(): void
    {
        $pipelineA = new MiddlewarePipeline;
        $pipelineB = new MiddlewarePipeline;

        $pipelineA->onRequest(fn (): null => null, 'howdy');
        $pipelineB->onRequest(fn (): null => null, 'howdy');

        $this->expectException(DuplicatePipeNameException::class);
        $this->expectExceptionMessage('The "howdy" pipe already exists on the pipeline');

        $pipelineA->merge($pipelineB);
    }

    public function testAMiddlewarePipelineIsCorrectlyDestructedWhenFinished(): void
    {
        $pipeline = new MiddlewarePipeline;
        $pipelineReference = WeakReference::create($pipeline);

        $pipeline
            ->onRequest(function (): void {
            })
            ->onResponse(function (): void {
            }, order: PipeOrder::LAST)
            ->onResponse(function (): void {
            })
            ->onFatalException(function (): void {
            });

        $this->assertInstanceOf(Pipeline::class, $pipeline->requestPipeline());
        $this->assertCount(1, $pipeline->requestPipeline()->pipes());
        $this->assertInstanceOf(Pipeline::class, $pipeline->responsePipeline());
        $this->assertCount(2, $pipeline->responsePipeline()->pipes());
        $this->assertCount(1, $pipeline->fatalPipeline()->pipes());
        $this->assertSame($pipeline, $pipelineReference->get());

        unset($pipeline);

        $this->assertNull($pipelineReference->get());
    }

    /**
     * Send a mocked request and return its response.
     */
    protected function response(): Response
    {
        return (new TestConnector)->send(new UserRequest, new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]));
    }

    /**
     * Create a fatal request exception for a pending request.
     */
    protected function fatalException(): FatalRequestException
    {
        return new FatalRequestException(
            new Exception('Could not resolve host.'),
            (new TestConnector)->createPendingRequest(new UserRequest),
        );
    }
}
