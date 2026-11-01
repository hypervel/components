<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Error;
use ErrorException;
use Exception;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Support\Collection;
use Hypervel\Support\Lottery;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\ExceptionWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Telescope\FeatureTestCase;
use LogicException;
use ParseError;
use PHPUnit\Framework\Attributes\TestWith;
use Throwable;

#[WithConfig('logging.default', 'null')]
#[WithConfig('telescope.watchers', [
    ExceptionWatcher::class => true,
])]
class ExceptionWatcherTest extends FeatureTestCase
{
    public function testExceptionWatcherRegisterEntries(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $exception = new BananaException('Something went bananas.');

        $handler->report($exception);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EXCEPTION, $entry->type);
        $this->assertSame(BananaException::class, $entry->content['class']);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($exception->getLine(), $entry->content['line']);
        $this->assertSame('Something went bananas.', $entry->content['message']);
        $this->assertArrayHasKey('trace', $entry->content);
    }

    public function testExceptionWatcherRegisterThrowableEntries(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $exception = new BananaError('Something went bananas.');

        $handler->report($exception);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EXCEPTION, $entry->type);
        $this->assertSame(BananaError::class, $entry->content['class']);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame($exception->getLine(), $entry->content['line']);
        $this->assertSame('Something went bananas.', $entry->content['message']);
        $this->assertArrayHasKey('trace', $entry->content);
    }

    public function testExceptionWatcherRegisterEntriesWhenEvalFailed(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $exception = null;

        try {
            eval('if (');

            $this->fail('eval() was expected to throw "syntax error, unexpected end of file"');
        } catch (ParseError $e) {
            // PsySH class ExecutionLoopClosure wraps ParseError in an exception.
            $exception = new ErrorException($e->getMessage(), $e->getCode(), 1, $e->getFile(), $e->getLine(), $e);
        }

        $handler->report($exception);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EXCEPTION, $entry->type);
        $this->assertSame(ErrorException::class, $entry->content['class']);
        $this->assertStringContainsString("eval()'d code", $entry->content['file']);
        $this->assertSame(1, $entry->content['line']);
        $this->assertSame("Unclosed '('", $entry->content['message']);
        $this->assertArrayHasKey('trace', $entry->content);
    }

    #[TestWith(['filter', false], 'individual filter')]
    #[TestWith(['filterBatch', false], 'batch filter')]
    #[TestWith(['filter', true], 'individual filter with deduplication')]
    #[TestWith(['filterBatch', true], 'batch filter with deduplication')]
    public function testReportableFiltersKeepAdmittedMappedExceptions(string $filterMethod, bool $deduplicate): void
    {
        Telescope::{$filterMethod}(static fn (IncomingEntry|Collection $entries): bool => $entries instanceof IncomingEntry
            ? $entries->isReportableException()
            : $entries->contains(static fn (IncomingEntry $entry): bool => $entry->isReportableException()));

        $handler = $this->app->make(ExceptionHandler::class);
        if ($deduplicate) {
            $handler->dontReportDuplicates();
        }

        $handler->map(BananaException::class, static fn (BananaException $exception): BananaException => new BananaException($exception->getMessage(), 0, $exception));
        $evaluations = 0;
        $handler->throttleUsing(static function (Throwable $exception) use (&$evaluations): Lottery {
            ++$evaluations;

            return Lottery::odds(1, 2);
        });
        Lottery::forceResultWithSequence([true, false], static fn (): bool => false);

        $exception = new BananaException('admitted failure');
        $handler->report($exception);
        $handler->report($exception);

        $this->assertCount(1, $this->loadTelescopeEntries());
        $this->assertSame($deduplicate ? 1 : 2, $evaluations);
    }

    public function testExceptionWatcherStoresExtraWhenContextFacadeUsed(): void
    {
        ContextRepository::getInstance()->add('tenant_id', 42);

        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report(new BananaException('Error with context'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertArrayHasKey('extra', $entry->content);
        $this->assertSame(42, $entry->content['extra']['tenant_id']);
    }

    public function testExceptionWatcherOmitsExtraWhenContextFacadeNotUsed(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report(new BananaException('Error without context'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertArrayNotHasKey('extra', $entry->content);
    }

    public function testExceptionWatcherHandlesADeletedSourceFile(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('TelescopeExceptionWatcherTest');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);
        $file = $directory . '/throws.php';
        $filesystem->put($file, '<?php throw new \Exception("deleted source");');

        try {
            require $file;
            $this->fail('The fixture was expected to throw.');
        } catch (Exception $exception) {
            $filesystem->delete($file);
        }

        try {
            $this->app->make(ExceptionHandler::class)->report($exception);

            $entry = $this->loadTelescopeEntries()->first();

            $this->assertSame('deleted source', $entry->content['message']);
            $this->assertSame([], $entry->content['line_preview']);
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testExceptionWatcherPreviewsTheStartOfTheFileForAnExceptionNearTheTop(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('TelescopeExceptionWatcherTest');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);
        $file = $directory . '/throws.php';
        $filesystem->put($file, "<?php\nthrow new \\LogicException('near the top');\n" . str_repeat("//\n", 30));

        try {
            try {
                require $file;
                $this->fail('The fixture was expected to throw.');
            } catch (LogicException $exception) {
                $this->app->make(ExceptionHandler::class)->report($exception);
            }

            $preview = $this->loadTelescopeEntries()->first()->content['line_preview'];

            $this->assertSame(range(1, 20), array_keys($preview));
            $this->assertSame("throw new \\LogicException('near the top');", $preview[2]);
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }
}

class BananaException extends Exception
{
}

class BananaError extends Error
{
}
