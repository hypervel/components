<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use Hypervel\Contracts\Filesystem\Cloud;
use Hypervel\Filesystem\Concerns\InteractsWithPooledFilesystem;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolErrorReporter;
use RuntimeException;
use Throwable;

class ClientPooledFilesystem implements Cloud
{
    use InteractsWithPooledFilesystem;

    /**
     * Create a client-pooled filesystem.
     *
     * @param Closure(): object $clientFactory
     * @param Closure(object): FilesystemAdapter $stackFactory
     * @param ?Closure(object): void $releaseCallback
     */
    public function __construct(
        protected PoolDefinition $definition,
        protected Closure $clientFactory,
        protected Closure $stackFactory,
        protected Factory $pools,
        protected array $config,
        protected ?Closure $releaseCallback = null,
    ) {
    }

    /**
     * Get the pooled client's definition.
     */
    public function getDefinition(): PoolDefinition
    {
        return $this->definition;
    }

    /**
     * Get the pooled client's identity.
     */
    public function getPoolName(): string
    {
        return $this->definition->identity;
    }

    /**
     * Remove and close the current client pool.
     */
    public function invalidatePool(): bool
    {
        return $this->pools->remove($this->definition->identity);
    }

    /**
     * Invoke a synchronous method against a per-operation stack.
     */
    protected function invoke(string $method, array $parameters): mixed
    {
        return $this->withStack(
            fn (FilesystemAdapter $stack): mixed => $stack->{$method}(...$parameters),
        );
    }

    /**
     * Run an operation against a stack built around a borrowed client.
     *
     * @param Closure(FilesystemAdapter): mixed $operation
     */
    protected function withStack(Closure $operation): mixed
    {
        return $this->withBorrowed(
            fn (FilesystemAdapter $stack, object $client): mixed => $operation($stack),
        );
    }

    /**
     * Run an operation against a stack and its borrowed client.
     *
     * @param Closure(FilesystemAdapter, object): mixed $operation
     */
    protected function withBorrowed(Closure $operation): mixed
    {
        [$lease, $stack] = $this->leaseStack();

        try {
            $result = $operation($stack, $lease->get());
        } catch (Throwable $operationException) {
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
        }

        $lease->release();

        return $result;
    }

    /**
     * Borrow a client and build its per-operation adapter stack.
     *
     * @return array{Lease, FilesystemAdapter}
     */
    protected function leaseStack(): array
    {
        $pool = $this->pools->getOrCreate($this->definition, $this->clientFactory);
        $lease = new Lease($pool, $pool->get(), $this->releaseCallback);

        try {
            return [$lease, $this->buildStack($lease->get())];
        } catch (Throwable $exception) {
            try {
                $lease->discard();
            } catch (Throwable $discardException) {
                PoolErrorReporter::report($discardException);
            }

            throw $exception;
        }
    }

    /**
     * Build a disk stack and apply every proxy-held callback.
     */
    protected function buildStack(object $client): FilesystemAdapter
    {
        $stack = ($this->stackFactory)($client);

        if (! $stack instanceof FilesystemAdapter) {
            throw new RuntimeException(
                'Client-pooled filesystem stack factories must return an instance of ' . FilesystemAdapter::class . '.',
            );
        }

        if ($this->serveCallback !== null) {
            $stack->serveUsing($this->serveCallback);
        }

        if ($this->temporaryUrlCallback !== null) {
            $stack->buildTemporaryUrlsUsing($this->temporaryUrlCallback);
        }

        if ($this->temporaryUploadUrlCallback !== null) {
            $stack->buildTemporaryUploadUrlsUsing($this->temporaryUploadUrlCallback);
        }

        return $stack;
    }

    /**
     * Open a stream that owns its client lease until closure.
     *
     * @param Closure(FilesystemAdapter): mixed $operation
     * @return null|resource
     */
    protected function leasedStream(Closure $operation): mixed
    {
        [$lease, $stack] = $this->leaseStack();

        try {
            $stream = $operation($stack);
        } catch (Throwable $operationException) {
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
        }

        if (! is_resource($stream)) {
            $lease->release();

            return $stream;
        }

        return LeasedStream::wrap($stream, $lease);
    }

    /**
     * Run a callback with an accessor result from a per-operation stack.
     */
    protected function withBorrowedAccessor(string $accessor, Closure $callback): mixed
    {
        return $this->withBorrowed(
            fn (FilesystemAdapter $stack, object $client): mixed => $callback(
                $accessor === 'getClient' ? $client : $stack->{$accessor}(),
            ),
        );
    }
}
