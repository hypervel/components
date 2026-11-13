<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\SwallowedCancellations;

use DateInterval;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ForeignCancellationException;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\MySqlBuilder;
use Hypervel\Engine\Channel as EngineChannel;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Sleep;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Throwable;

class WrappedFailure extends RuntimeException
{
    public function __construct(string $message, Throwable $cause)
    {
        parent::__construct($message, 0, $cause);
    }
}

class UnchainedFailure extends RuntimeException
{
    public function __construct(string $message, Throwable $cause)
    {
        parent::__construct($message);
    }
}

class ReplacedCause extends RuntimeException
{
    public function __construct(Throwable $cause)
    {
        $cause = new RuntimeException('Hidden.', 0, $cause->getPrevious());

        parent::__construct('Import failed.', 0, $cause);
    }
}

class ConditionalCause extends RuntimeException
{
    public function __construct(Throwable $cause, bool $chain)
    {
        if ($chain) {
            parent::__construct('Import failed.', 0, $cause);
        } else {
            parent::__construct('Import failed.');
        }
    }
}

class BufferedChannel extends EngineChannel
{
    public function pop(float $timeout = -1): mixed
    {
        return null;
    }
}

class Import extends Model
{
    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}

class Item extends Model
{
}

class Tag extends Model
{
}

class ArchivedImport extends Model
{
    use SoftDeletes;
}

class KeptImport extends Model
{
    use SoftDeletes;

    public function restore(): bool
    {
        return true;
    }
}

class SilentSleep extends Sleep
{
    protected function goodnight(): void
    {
    }
}

class ImportJob implements ShouldQueue
{
    /**
     * @param EloquentCollection<int, Import> $imports
     */
    public function handle(
        Channel $channel,
        EngineChannel $held,
        Container $app,
        Import $import,
        EloquentCollection $imports,
        ArchivedImport $archived,
        KeptImport $kept,
        MySqlBuilder $schema,
    ): void
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $fallback = null;
        }

        try {
            $channel->pop(1.0);
        } catch (Throwable $exception) {
            return;
        }

        try {
            Import::query()->count();
        } catch (CanceledException) {
            return;
        }

        try {
            Redis::get('import');
        } catch (Throwable $exception) {
            throw new UnchainedFailure('Import failed.', $exception);
        }

        try {
            usleep(1000);
        } catch (Throwable $exception) {
            throw new ForeignCancellationException('Import canceled.', previous: $exception);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                return;
            }

            throw $exception;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            throw $exception;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            throw new WrappedFailure('Import failed.', $exception);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            report($exception);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            if (! Coroutine::causedByCancellation($exception)) {
                report($exception);

                return;
            }

            throw $exception;
        }

        try {
            Sleep::usleep(1000);
        } catch (Throwable) {
            return;
        }

        try {
            Sleep::for(1)->second()->and(5)->milliseconds();
        } catch (Throwable) {
            return;
        }

        try {
            Sleep::for(1)->second()->then(static fn (): null => null);
        } catch (Throwable) {
            return;
        }

        $sleep = Sleep::for(1);

        try {
            $sleep->second();
        } catch (Throwable) {
            return;
        }

        try {
            $value = Sleep::for(1)->second()->then(static fn (): int => 1);
        } catch (Throwable) {
            return;
        }

        try {
            SilentSleep::for(1)->second();
            SilentSleep::for(1)->second()->then(static fn (): null => null);
        } catch (Throwable) {
            return;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            foreach ([1, 2] as $index) {
                switch ($index) {
                    case 1:
                        continue 2;
                    default:
                        break;
                }
            }

            throw $exception;
        }

        foreach ([1, 2] as $attempt) {
            try {
                DB::select('select 1');
            } catch (Throwable $exception) {
                if ($attempt === 1) {
                    continue;
                }

                throw $exception;
            }
        }

        try {
            $query = Import::query();
            $query->where('status', 'pending')->get();
        } catch (Throwable) {
            return;
        }

        try {
            $queue = new Channel(1);
            $queue->pop(1.0);
        } catch (Throwable) {
            return;
        }

        try {
            $channel = new BufferedChannel(1);
            $channel->pop(1.0);
        } catch (Throwable) {
            return;
        }

        try {
            $held = new BufferedChannel(1);
            $alias = $held;
            $alias->pop(1.0);
        } catch (Throwable) {
            return;
        }

        try {
            $query = Import::query();
            $tenant = 'acme';
            $query->where('tenant', $tenant)->get();
        } catch (Throwable) {
            return;
        }

        try {
            $ids = [1, 2];
            Import::whereIn('id', $ids)->update(['status' => 'done']);
        } catch (Throwable) {
            return;
        }

        try {
            $model = Import::class;
            $model::create(['status' => 'pending']);
        } catch (Throwable) {
            return;
        }

        $class = EngineChannel::class;

        try {
            $class = BufferedChannel::class;
            $app->make($class)->pop(1.0);
        } catch (Throwable) {
            return;
        }

        $class = EngineChannel::class;

        try {
            $class = BufferedChannel::class;
            $buffer = app($class);
            $buffer->pop(1.0);
        } catch (Throwable) {
            return;
        }

        try {
            DB::table('imports')->truncate();
        } catch (Throwable) {
            return;
        }

        try {
            $import->increment('attempts');
        } catch (Throwable) {
            return;
        }

        try {
            $import->load('items');
        } catch (Throwable) {
            return;
        }

        try {
            $import->items()->get();
        } catch (Throwable) {
            return;
        }

        try {
            $import->items()->count();
        } catch (Throwable) {
            return;
        }

        try {
            $import->tags()->attach(1);
        } catch (Throwable) {
            return;
        }

        try {
            $archived->restore();
        } catch (Throwable) {
            return;
        }

        try {
            ArchivedImport::onlyTrashed()->restore();
        } catch (Throwable) {
            return;
        }

        try {
            $imports->load('items');
        } catch (Throwable) {
            return;
        }

        try {
            Schema::hasTable('imports');
        } catch (Throwable) {
            return;
        }

        try {
            $schema->dropAllTables();
        } catch (Throwable) {
            return;
        }

        try {
            $kept->restore();
            $schema->dropAllTypes();
            $imports->find(1);
            $imports->toQuery();
            $import->items()->lazy();
        } catch (Throwable) {
            return;
        }

        try {
            try {
                usleep(1000);
            } catch (Throwable) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        try {
            try {
                usleep(1000);
            } catch (Throwable $exception) {
                throw $exception;
            }
        } catch (Throwable) {
            return;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            $exception = new RuntimeException('Import failed.');

            throw $exception;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            $exception = new WrappedFailure('Import failed.', $exception);

            throw $exception;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            throw new ReplacedCause($exception);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            throw new ConditionalCause($exception, true);
        }

        try {
            DB::table('imports')->cursor();
            Redis::pipeline();
            Redis::connection('cache');
        } catch (Throwable) {
            return;
        }

        try {
            DB::select('select 1');
        } catch (CanceledException $exception) {
            $cancellation = $exception;
        } catch (Throwable) {
            return;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            if ($import->exists) {
                throw new RuntimeException('Import failed.');
            }

            throw $exception;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw new RuntimeException('Import canceled.');
            }

            throw $exception;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            if ($import->exists) {
                throw new WrappedFailure('Import failed.', $exception);
            }

            try {
                throw new RuntimeException('Cleanup failed.');
            } catch (RuntimeException) {
                $cleanupFailed = true;
            }

            throw $exception;
        }

        $connection = $import->exists ? DB::connection() : null;

        try {
            $connection ??= DB::connection('replica');
            $connection->select('select 1');
        } catch (Throwable) {
            return;
        }
    }
}

class ImportService
{
    public function run(): void
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return;
        }
    }
}

class SyncImportJob implements ShouldQueue
{
    /**
     * @param list<mixed> $rows
     */
    public function handle(BufferedChannel $buffer, EngineChannel $channel, Import $import, array $rows): void
    {
        try {
            Redis::connection('cache')->pipeline(function (): void {
            });
        } catch (Throwable) {
            return;
        }

        try {
            foreach (Import::lazy() as $lazyImport) {
                $rows[] = $lazyImport;
            }
        } catch (Throwable) {
            return;
        }

        try {
            DB::table('imports')->cursor()->each(function (object $row): void {
            });
        } catch (Throwable) {
            return;
        }

        try {
            foreach (DB::connection()->cursor('select 1') as $row) {
                $rows[] = $row;
            }
        } catch (Throwable) {
            return;
        }

        try {
            foreach ($import->tags()->lazy() as $tag) {
                $rows[] = $tag;
            }
        } catch (Throwable) {
            return;
        }

        try {
            $buffer ??= new EngineChannel(1);
            $buffer->pop(1.0);
        } catch (Throwable) {
            return;
        }

        try {
            $lazy = Import::lazy();

            foreach ($lazy as $lazyImport) {
                $rows[] = $lazyImport;
            }
        } catch (Throwable) {
            return;
        }

        try {
            Import::lazy()->map(fn (Import $lazyImport): int => 1)->each(function (int $value): void {
            });
        } catch (Throwable) {
            return;
        }

        try {
            $consume = Import::lazy()->each(...);
        } catch (Throwable) {
            return;
        }

        try {
            Import::lazy()?->each(function (Import $lazyImport): void {
            });
        } catch (Throwable) {
            return;
        }

        try {
            $class = BufferedChannel::class;
            $channel ??= new $class(1);
            $channel->pop(1.0);
        } catch (Throwable) {
            return;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            foreach ([new RuntimeException('Import failed.')] as $exception) {
                report($exception);
            }

            throw $exception;
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            throw new DestructuredCause($exception);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            throw new RepeatedCause($exception, 'Import failed.', $exception);
        }

        try {
            Sleep::for(1)?->second();
        } catch (Throwable) {
            return;
        }

        try {
            QuietSleep::for(1)->second();
            QuietSleep::for(1)->then(static fn (): null => null);
        } catch (Throwable) {
            return;
        }
    }
}

class DestructuredCause extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        [$previous] = [new RuntimeException('Hidden.')];

        parent::__construct('Import failed.', 0, $previous);
    }
}

class RepeatedCause extends RuntimeException
{
    public function __construct(Throwable $cause, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

class QuietSleep extends Sleep
{
    public function __construct(DateInterval|float|int $duration)
    {
        parent::__construct($duration);

        $this->shouldSleep = false;
    }
}
