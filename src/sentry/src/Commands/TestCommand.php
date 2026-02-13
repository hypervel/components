<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Commands;

use Exception;
use Hypervel\Console\Command;
use Hypervel\Contracts\Container\Container;
use Sentry\ClientBuilder;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Throwable;

class TestCommand extends Command
{
    protected ?string $signature = 'sentry:test {--dsn= : Sentry DSN} {--transaction= : Transaction}';

    protected string $description = 'Generate a test event and send it to Sentry.';

    public function __construct(protected Container $container)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        try {
            $hub = $this->container->make(HubInterface::class);

            if ($this->option('dsn')) {
                $hub = new Hub(ClientBuilder::create(['dsn' => $this->option('dsn')])->getClient());
            }

            if ($hub->getClient()->getOptions()->getDsn()) {
                $this->info('[Sentry] DSN discovered!');
            } else {
                $this->error('[Sentry] Could not discover DSN!');
                $this->error(
                    '[Sentry] Please check if your DSN is set properly in your config or `.env` as `SENTRY_DSN`.'
                );

                return;
            }

            if ($this->option('transaction')) {
                $hub->getClient()->getOptions()->setTracesSampleRate(1);
            }

            $transactionContext = new TransactionContext();
            $transactionContext->setName('Sentry Test Transaction');
            $transactionContext->setOp('sentry.test');
            $transaction = $hub->startTransaction($transactionContext);

            $spanContext = new SpanContext();
            $spanContext->setOp('sentry.sent');
            $span = $transaction->startChild($spanContext);

            $this->info('[Sentry] Generating test Event');

            $ex = $this->generateTestException('command name', ['foo' => 'bar']);

            $eventId = $hub->captureException($ex);

            $this->info('[Sentry] Sending test Event');

            $span->finish();
            $result = $transaction->finish();

            if ($result) {
                $this->info("[Sentry] Transaction sent: {$result}");
            }

            if (! $eventId) {
                $this->error('[Sentry] There was an error sending the test event.');
                $this->error(
                    '[Sentry] Please check if your DSN is set properly in your config or `.env` as `SENTRY_DSN`.'
                );
            } else {
                $this->info("[Sentry] Event sent with ID: {$eventId}");
            }
        } catch (Throwable $e) {
            $this->error("[Sentry] {$e->getMessage()}");
        }
    }

    /**
     * Generate a test exception to send to Sentry.
     *
     * @param mixed $command
     * @param mixed $arg
     */
    protected function generateTestException($command, $arg): ?Throwable
    {
        // Do something silly
        try {
            throw new Exception('This is a test exception sent from the Sentry Hypervel SDK.');
        } catch (Throwable $e) {
            return $e;
        }
    }
}
