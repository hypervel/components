<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Error;
use ErrorException;
use Exception;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ExceptionWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use ParseError;

/**
 * @internal
 * @coversNothing
 */
class ExceptionWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get('config')
            ->set('telescope.watchers', [
                ExceptionWatcher::class => true,
            ]);
        $this->app->get('config')
            ->set('logging.default', 'null');

        $this->startTelescope();
    }

    public function testExceptionWatcherRegisterEntries()
    {
        $handler = $this->app->get(ExceptionHandler::class);

        $exception = new BananaException('Something went bananas.');

        $handler->report($exception);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EXCEPTION, $entry->type);
        $this->assertSame(BananaException::class, $entry->content['class']);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame('Something went bananas.', $entry->content['message']);
        $this->assertArrayHasKey('trace', $entry->content);
    }

    public function testExceptionWatcherRegisterThrowableEntries()
    {
        $handler = $this->app->get(ExceptionHandler::class);

        $exception = new BananaError('Something went bananas.');

        $handler->report($exception);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EXCEPTION, $entry->type);
        $this->assertSame(BananaError::class, $entry->content['class']);
        $this->assertSame(__FILE__, $entry->content['file']);
        $this->assertSame('Something went bananas.', $entry->content['message']);
        $this->assertArrayHasKey('trace', $entry->content);
    }

    public function testExceptionWatcherRegisterEntriesWhenEvalFailed()
    {
        $handler = $this->app->get(ExceptionHandler::class);

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
}

class BananaException extends Exception
{
}

class BananaError extends Error
{
}
