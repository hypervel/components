<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Database\Events\ConnectionEvent;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Sentry\Integrations\Integration;
use Sentry\Breadcrumb;

class DbQueryFeature extends Feature
{
    protected const SQL_QUERIES_BREADCRUMB_FEATURE_KEY = 'sql_queries';

    protected const SQL_BINDINGS_BREADCRUMB_FEATURE_KEY = 'sql_bindings';

    protected const SQL_TRANSACTION_BREADCRUMB_FEATURE_KEY = 'sql_transaction';

    public function isApplicable(): bool
    {
        return $this->switcher->isBreadcrumbEnable(static::SQL_QUERIES_BREADCRUMB_FEATURE_KEY)
            || $this->switcher->isBreadcrumbEnable(static::SQL_TRANSACTION_BREADCRUMB_FEATURE_KEY);
    }

    public function onBoot(): void
    {
        $dispatcher = $this->container->get(Dispatcher::class);

        $dispatcher->listen(QueryExecuted::class, [$this, 'handleQueryExecutedEvent']);
        $dispatcher->listen(TransactionBeginning::class, [$this, 'handleTransactionEvent']);
        $dispatcher->listen(TransactionCommitted::class, [$this, 'handleTransactionEvent']);
        $dispatcher->listen(TransactionRolledBack::class, [$this, 'handleTransactionEvent']);
    }

    public function handleQueryExecutedEvent(QueryExecuted $event): void
    {
        if (! $this->switcher->isBreadcrumbEnable(static::SQL_QUERIES_BREADCRUMB_FEATURE_KEY)) {
            return;
        }

        $data = ['connectionName' => $event->connectionName];

        if ($event->time !== null) {
            $data['executionTimeMs'] = $event->time;
        }

        if ($this->switcher->isBreadcrumbEnable(static::SQL_BINDINGS_BREADCRUMB_FEATURE_KEY)) {
            $data['bindings'] = $event->bindings;
        }

        Integration::addBreadcrumb(
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'sql.query',
                $event->sql,
                $data
            )
        );
    }

    public function handleTransactionEvent(
        TransactionBeginning|TransactionCommitted|TransactionRolledBack|ConnectionEvent $event
    ): void {
        if (! $this->switcher->isBreadcrumbEnable(static::SQL_TRANSACTION_BREADCRUMB_FEATURE_KEY)) {
            return;
        }

        $data = [
            'connectionName' => $event->connectionName,
        ];

        Integration::addBreadcrumb(
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'sql.transaction',
                $event::class,
                $data
            )
        );
    }
}
