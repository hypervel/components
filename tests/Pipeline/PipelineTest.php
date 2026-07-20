<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pipeline;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Tests\Pipeline\Fixtures\FooPipeline;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

class PipelineTest extends TestCase
{
    public function testPipelineBasicUsage(): void
    {
        $pipeTwo = function ($piped, $next) {
            $_SERVER['__test.pipe.two'] = $piped;

            return $next($piped);
        };

        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([PipelineTestPipeOne::class, $pipeTwo])
            ->then(function ($piped) {
                return $piped;
            });

        $this->assertSame('foo', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);
        $this->assertSame('foo', $_SERVER['__test.pipe.two']);

        unset($_SERVER['__test.pipe.one'], $_SERVER['__test.pipe.two']);
    }

    public function testPipelineUsageWithObjects(): void
    {
        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([new PipelineTestPipeOne])
            ->then(function ($piped) {
                return $piped;
            });

        $this->assertSame('foo', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);

        unset($_SERVER['__test.pipe.one']);
    }

    public function testPipelineUsageWithInvokableObjects(): void
    {
        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([new PipelineTestPipeTwo])
            ->then(
                function ($piped) {
                    return $piped;
                }
            );

        $this->assertSame('foo', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);

        unset($_SERVER['__test.pipe.one']);
    }

    public function testPipelineUsageWithCallable(): void
    {
        $function = function ($piped, $next) {
            $_SERVER['__test.pipe.one'] = 'foo';

            return $next($piped);
        };

        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([$function])
            ->then(
                function ($piped) {
                    return $piped;
                }
            );

        $this->assertSame('foo', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);

        unset($_SERVER['__test.pipe.one']);

        $result = (new Pipeline(new Container))
            ->send('bar')
            ->through($function)
            ->thenReturn();

        $this->assertSame('bar', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);

        unset($_SERVER['__test.pipe.one']);
    }

    public function testPipelineUsageWithPipe(): void
    {
        $object = new stdClass;

        $object->value = 0;

        $function = function ($object, $next) {
            ++$object->value;

            return $next($object);
        };

        $result = (new Pipeline(new Container))
            ->send($object)
            ->through([$function])
            ->pipe([$function])
            ->then(
                function ($piped) {
                    return $piped;
                }
            );

        $this->assertSame($object, $result);
        $this->assertEquals(2, $object->value);
    }

    public function testPipelineThroughMethodOverwritesPreviouslySetAndAppendedPipes(): void
    {
        $object = new stdClass;

        $object->value = 0;

        $function = function ($object, $next) {
            ++$object->value;

            return $next($object);
        };

        $result = (new Pipeline(new Container))
            ->send($object)
            ->through([$function])
            ->pipe([$function])
            ->through([$function])
            ->then(fn ($piped) => $piped);

        $this->assertSame($object, $result);
        $this->assertEquals(1, $object->value);
    }

    public function testPipelineUsageWithInvokableClass(): void
    {
        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([PipelineTestPipeTwo::class])
            ->then(
                function ($piped) {
                    return $piped;
                }
            );

        $this->assertSame('foo', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);

        unset($_SERVER['__test.pipe.one']);
    }

    public function testThenMethodIsNotCalledIfThePipeReturns(): void
    {
        $_SERVER['__test.pipe.then'] = '(*_*)';
        $_SERVER['__test.pipe.second'] = '(*_*)';

        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([
                fn ($value, $next) => 'm(-_-)m',
                fn ($value, $next) => $_SERVER['__test.pipe.second'] = 'm(-_-)m',
            ])
            ->then(function ($piped) {
                $_SERVER['__test.pipe.then'] = '(0_0)';

                return $piped;
            });

        $this->assertSame('m(-_-)m', $result);
        // The then callback is not called.
        $this->assertSame('(*_*)', $_SERVER['__test.pipe.then']);
        // The second pipe is not called.
        $this->assertSame('(*_*)', $_SERVER['__test.pipe.second']);

        unset($_SERVER['__test.pipe.then']);
    }

    public function testThenMethodInputValue(): void
    {
        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([function ($value, $next) {
                $value = $next('::not_foo::');

                $_SERVER['__test.pipe.return'] = $value;

                return 'pipe::' . $value;
            }])
            ->then(function ($piped) {
                $_SERVER['__test.then.arg'] = $piped;

                return 'then' . $piped;
            });

        $this->assertSame('pipe::then::not_foo::', $result);
        $this->assertSame('::not_foo::', $_SERVER['__test.then.arg']);

        unset($_SERVER['__test.then.arg'], $_SERVER['__test.pipe.return']);
    }

    public function testPipelineUsageWithParameters(): void
    {
        $parameters = ['one', 'two'];

        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through(PipelineTestParameterPipe::class . ':' . implode(',', $parameters))
            ->then(function ($piped) {
                return $piped;
            });

        $this->assertSame('foo', $result);
        $this->assertEquals($parameters, $_SERVER['__test.pipe.parameters']);

        unset($_SERVER['__test.pipe.parameters']);
    }

    public function testPipelineViaChangesTheMethodBeingCalledOnThePipes(): void
    {
        $pipelineInstance = new Pipeline(new Container);
        $result = $pipelineInstance->send('data')
            ->through(PipelineTestPipeOne::class)
            ->via('differentMethod')
            ->then(function ($piped) {
                return $piped;
            });
        $this->assertSame('data', $result);
    }

    public function testPipelineThrowsExceptionOnResolveWithoutContainer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A container instance has not been passed to the Pipeline.');

        (new Pipeline)->send('data')
            ->through(PipelineTestPipeOne::class)
            ->then(function ($piped) {
                return $piped;
            });
    }

    public function testPipelineThrowsExceptionWhenUsingTransactionsWithoutContainer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A container instance has not been passed to the Pipeline.');

        (new Pipeline)->send('data')
            ->through(PipelineTestPipeOne::class)
            ->withinTransaction()
            ->then(function ($piped) {
                return $piped;
            });
    }

    public function testPipelineDelegatesIntegerBackedEnumTransactionConnection(): void
    {
        $container = new Container;
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
        $manager = m::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')->once()->with(PipelineConnectionName::Zero)->andReturn($connection);
        $container->instance('db', $manager);

        $result = (new Pipeline($container))
            ->send('data')
            ->withinTransaction(PipelineConnectionName::Zero)
            ->thenReturn();

        $this->assertSame('data', $result);
    }

    public function testPipelineThenReturnMethodRunsPipelineThenReturnsPassable(): void
    {
        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([PipelineTestPipeOne::class])
            ->thenReturn();

        $this->assertSame('foo', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);

        unset($_SERVER['__test.pipe.one']);
    }

    public function testPipelineConditionable(): void
    {
        $result = (new Pipeline(new Container))
            ->send('foo')
            ->when(true, function (Pipeline $pipeline) {
                $pipeline->pipe([PipelineTestPipeOne::class]);
            })
            ->then(function ($piped) {
                return $piped;
            });

        $this->assertSame('foo', $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);
        unset($_SERVER['__test.pipe.one']);

        $_SERVER['__test.pipe.one'] = null;
        $result = (new Pipeline(new Container))
            ->send('foo')
            ->when(false, function (Pipeline $pipeline) {
                $pipeline->pipe([PipelineTestPipeOne::class]);
            })
            ->then(function ($piped) {
                return $piped;
            });

        $this->assertSame('foo', $result);
        $this->assertNull($_SERVER['__test.pipe.one']);
        unset($_SERVER['__test.pipe.one']);
    }

    public function testPipelineFinally(): void
    {
        $pipeTwo = function ($piped, $next) {
            $_SERVER['__test.pipe.two'] = $piped;

            $next($piped);
        };

        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([PipelineTestPipeOne::class, $pipeTwo])
            ->finally(function ($piped) {
                $_SERVER['__test.pipe.finally'] = $piped;
            })
            ->then(function ($piped) {
                return $piped;
            });

        $this->assertSame(null, $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);
        $this->assertSame('foo', $_SERVER['__test.pipe.two']);
        $this->assertSame('foo', $_SERVER['__test.pipe.finally']);

        unset($_SERVER['__test.pipe.one'], $_SERVER['__test.pipe.two'], $_SERVER['__test.pipe.finally']);
    }

    public function testPipelineFinallyMethodWhenChainIsStopped(): void
    {
        $pipeTwo = function ($piped) {
            $_SERVER['__test.pipe.two'] = $piped;
        };

        $result = (new Pipeline(new Container))
            ->send('foo')
            ->through([PipelineTestPipeOne::class, $pipeTwo])
            ->finally(function ($piped) {
                $_SERVER['__test.pipe.finally'] = $piped;
            })
            ->then(function ($piped) {
                return $piped;
            });

        $this->assertSame(null, $result);
        $this->assertSame('foo', $_SERVER['__test.pipe.one']);
        $this->assertSame('foo', $_SERVER['__test.pipe.two']);
        $this->assertSame('foo', $_SERVER['__test.pipe.finally']);

        unset($_SERVER['__test.pipe.one'], $_SERVER['__test.pipe.two'], $_SERVER['__test.pipe.finally']);
    }

    public function testPipelineFinallyOrder(): void
    {
        $std = new stdClass;

        $result = (new Pipeline(new Container))
            ->send($std)
            ->through([
                function ($std, $next) {
                    $std->value = 1;

                    return $next($std);
                },
                function ($std, $next) {
                    ++$std->value;

                    return $next($std);
                },
            ])->finally(function ($std) {
                $this->assertSame(3, $std->value);

                ++$std->value;
            })->then(function ($std) {
                ++$std->value;

                return $std;
            });

        $this->assertSame(4, $std->value);
        $this->assertSame(4, $result->value);
    }

    public function testPipelineFinallyWhenExceptionOccurs(): void
    {
        $std = new stdClass;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('My Exception: 1');

        try {
            (new Pipeline(new Container))
                ->send($std)
                ->through([
                    function ($std, $next) {
                        $std->value = 1;

                        return $next($std);
                    },
                    function ($std) {
                        throw new Exception('My Exception: ' . $std->value);
                    },
                ])->finally(function ($std) {
                    $this->assertSame(1, $std->value);

                    ++$std->value;
                })->then(function ($std) {
                    $std->value = 0;

                    return $std;
                });
        } catch (Exception $e) {
            $this->assertSame('My Exception: 1', $e->getMessage());
            $this->assertSame(2, $std->value);

            throw $e;
        }
    }

    public function testHandleCarry(): void
    {
        $result = (new FooPipeline(new Container))
            ->send($id = rand(0, 99))
            ->through([PipelineTestPipeOne::class])
            ->via('incr')
            ->then(static function ($passable) {
                if (is_int($passable)) {
                    $passable += 3;
                }

                return $passable;
            });

        $this->assertSame($id + 6, $result);
    }

    public function testPipelineMacro(): void
    {
        Pipeline::macro('customMethod', function ($value) {
            return 'custom_' . $value;
        });

        $pipeline = new Pipeline(new Container);
        $this->assertTrue($pipeline->hasMacro('customMethod'));
        $this->assertSame('custom_test', $pipeline->customMethod('test'));
    }

    public function testPipelineMacroWithThis(): void
    {
        Pipeline::macro('getPipes', function () {
            return $this->pipes;
        });

        $pipeline = new Pipeline(new Container);
        $pipeline->through(['pipe1', 'pipe2']);

        $this->assertEquals(['pipe1', 'pipe2'], $pipeline->getPipes());
    }

    public function testPipelineHasMacro(): void
    {
        Pipeline::macro('existingMacro', function () {
            return 'exists';
        });

        $pipeline = new Pipeline(new Container);

        $this->assertTrue($pipeline->hasMacro('existingMacro'));
        $this->assertFalse($pipeline->hasMacro('nonExistingMacro'));
    }

    public function testPipelineMacroOverwrite(): void
    {
        Pipeline::macro('testMacro', function () {
            return 'first';
        });

        $pipeline = new Pipeline(new Container);
        $this->assertSame('first', $pipeline->testMacro());

        Pipeline::macro('testMacro', function () {
            return 'second';
        });

        $pipeline2 = new Pipeline(new Container);
        $this->assertSame('second', $pipeline2->testMacro());
    }
}

enum PipelineConnectionName: int
{
    case Zero = 0;
}

class PipelineTestPipeOne
{
    public function handle($piped, $next)
    {
        $_SERVER['__test.pipe.one'] = $piped;

        return $next($piped);
    }

    public function differentMethod($piped, $next)
    {
        return $next($piped);
    }

    public function incr($piped, $next)
    {
        return $next(++$piped);
    }
}

class PipelineTestPipeTwo
{
    public function __invoke($piped, $next)
    {
        $_SERVER['__test.pipe.one'] = $piped;

        return $next($piped);
    }
}

class PipelineTestParameterPipe
{
    public function handle($piped, $next, $parameter1 = null, $parameter2 = null)
    {
        $_SERVER['__test.pipe.parameters'] = [$parameter1, $parameter2];

        return $next($piped);
    }
}
