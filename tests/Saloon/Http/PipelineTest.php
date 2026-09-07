<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Saloon\Enums\PipeOrder;
use Hypervel\Saloon\Exceptions\DuplicatePipeNameException;
use Hypervel\Saloon\Http\Pipeline;
use Hypervel\Tests\TestCase;

class PipelineTest extends TestCase
{
    public function testPipelineCanBeExecuted(): void
    {
        $pipeline = (new Pipeline)
            ->pipe(fn (int $number): int => $number + 5)
            ->pipe(fn (int $number): int => $number * 2)
            ->pipe(fn (int $number): int => $number - 3);

        $this->assertCount(3, $pipeline->pipes());
        $this->assertSame(7, $pipeline->process(0));
    }

    public function testPipesAreStoredInExecutionOrder(): void
    {
        $pipeline = (new Pipeline)
            ->pipe(fn (array $values): array => [...$values, 'default-one'], 'default-one')
            ->pipe(fn (array $values): array => [...$values, 'last'], 'last', PipeOrder::Last)
            ->pipe(fn (array $values): array => [...$values, 'first'], 'first', PipeOrder::First)
            ->pipe(fn (array $values): array => [...$values, 'default-two'], 'default-two');

        $this->assertSame(
            ['first', 'default-one', 'default-two', 'last'],
            $pipeline->process([]),
        );
        $this->assertSame(
            ['first', 'default-one', 'default-two', 'last'],
            array_map(static fn ($pipe): ?string => $pipe->name, $pipeline->pipes()),
        );
    }

    public function testDuplicateNamedPipeIsRejectedAcrossBuckets(): void
    {
        $pipeline = (new Pipeline)->pipe(fn (mixed $payload): mixed => $payload, 'duplicate', PipeOrder::First);

        $this->expectException(DuplicatePipeNameException::class);

        $pipeline->pipe(fn (mixed $payload): mixed => $payload, 'duplicate', PipeOrder::Last);
    }

    public function testPipesCanBeCopiedIntoAnotherPipeline(): void
    {
        $source = (new Pipeline)
            ->pipe(fn (array $values): array => [...$values, 'last'], 'last', PipeOrder::Last)
            ->pipe(fn (array $values): array => [...$values, 'first'], 'first', PipeOrder::First);

        $target = (new Pipeline)->setPipes($source->pipes());

        $this->assertSame(['first', 'last'], $target->process([]));
    }
}
