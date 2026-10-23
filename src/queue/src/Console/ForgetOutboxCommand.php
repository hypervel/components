<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Bus\UniqueLock;
use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Queue\Console\Concerns\FindsOutboxMessages;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'queue:outbox-forget')]
class ForgetOutboxCommand extends Command
{
    use ConfirmableTrait;
    use FindsOutboxMessages;

    protected ?string $signature = 'queue:outbox-forget
                            {uuid : The UUID of the job to discard}
                            {--database= : The configured outbox database containing the message}
                            {--force : Skip the confirmation prompt}';

    protected string $description = 'Permanently discard an outbox message and release its unique lock';

    /**
     * Create a new outbox forget command.
     */
    public function __construct(
        protected DatabaseOutboxRepository $repository,
        protected CacheFactory $cache,
        protected ExceptionHandler $exceptions,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $uuid = $this->argument('uuid');

        // Ask before querying so an interactive prompt never holds a database connection.
        if (! $this->confirmToProceed("Permanently discard outbox message [{$uuid}]", true)) {
            return self::FAILURE;
        }

        $found = $this->findOutboxMessage($uuid, $this->option('database'));

        if ($found === null) {
            $this->components->error("No outbox message matches UUID [{$uuid}].");

            return self::FAILURE;
        }

        $message = $found['message'];

        if (! $this->repository->forget($found['database'], (int) $message->id, $message->lease_token)) {
            $this->components->error("Outbox message [{$uuid}] is currently leased or changed during deletion.");

            return self::FAILURE;
        }

        try {
            $this->releaseUniqueLock($message->payload);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->components->error("Outbox message [{$uuid}] was deleted, but unique-lock cleanup failed: {$exception->getMessage()}");
            $this->exceptions->report($exception, ['outbox_database' => $found['database'], 'job_uuid' => $uuid]);

            return self::FAILURE;
        }

        $this->components->info("Outbox message [{$uuid}] was deleted.");

        return self::SUCCESS;
    }

    /**
     * Release exact stored ownership without constructing the job or hydrating application context.
     *
     * Override this method to establish a custom cache namespace or resolve an ad hoc repository.
     */
    protected function releaseUniqueLock(string $payload): void
    {
        $hidden = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['illuminate:log:context']['hidden'] ?? [];

        if (! array_key_exists('laravel_unique_job_key', $hidden)) {
            return;
        }

        $store = unserialize($hidden['laravel_unique_job_cache_store'] ?? 'N;', ['allowed_classes' => false]);
        $key = unserialize($hidden['laravel_unique_job_key'], ['allowed_classes' => false]);
        $owner = unserialize($hidden['laravel_unique_job_lock_owner'] ?? 'N;', ['allowed_classes' => false]);

        if (! is_string($store) || $store === '' || ! is_string($key) || $key === '' || ! is_string($owner) || $owner === '') {
            throw new LogicException('Unique-lock cleanup requires a named cache store, key, and owner token.');
        }

        $cache = $this->cache->store($store);

        if (! $cache->getStore() instanceof LockProvider) {
            throw new LogicException("Cache store [{$store}] does not support owner-matched lock cleanup.");
        }

        UniqueLock::releaseOwned($cache, $key, $owner);
    }
}
