<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Exception;
use Hypervel\Auth\Events as AuthEvents;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events as DatabaseEvents;
use Hypervel\Http\Request;
use Hypervel\Log\Events as LogEvents;
use Hypervel\Routing\Events as RoutingEvents;
use Hypervel\Sanctum\Events as Sanctum;
use Hypervel\Sentry\Tracing\Middleware;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\State\Scope;

class EventHandler
{
    /**
     * Map event handlers to events.
     *
     * @var array<class-string, string>
     */
    protected static array $eventHandlerMap = [
        LogEvents\MessageLogged::class => 'messageLogged',
        RoutingEvents\RouteMatched::class => 'routeMatched',
        DatabaseEvents\QueryExecuted::class => 'queryExecuted',
    ];

    /**
     * Map authentication event handlers to events.
     *
     * @var array<class-string, string>
     */
    protected static array $authEventHandlerMap = [
        AuthEvents\Authenticated::class => 'authenticated',
        Sanctum\TokenAuthenticated::class => 'sanctumTokenAuthenticated',
    ];

    private readonly bool $recordSqlQueries;

    private readonly bool $recordSqlBindings;

    private readonly bool $recordSqlTransactions;

    private readonly bool $recordLogs;

    /**
     * Create a new event handler instance.
     */
    public function __construct(
        private readonly Container $container,
        array $config,
    ) {
        $this->recordSqlQueries = ($config['breadcrumbs']['sql_queries'] ?? true) === true;
        $this->recordSqlBindings = ($config['breadcrumbs']['sql_bindings'] ?? false) === true;
        $this->recordSqlTransactions = ($config['breadcrumbs']['sql_transactions'] ?? true) === true;
        $this->recordLogs = ($config['breadcrumbs']['logs'] ?? true) === true;
    }

    /**
     * Attach all event handlers.
     */
    public function subscribe(Dispatcher $dispatcher): void
    {
        foreach (static::$eventHandlerMap as $eventName => $handler) {
            if ($eventName === DatabaseEvents\QueryExecuted::class && ! $this->recordSqlQueries) {
                continue;
            }

            if ($eventName === LogEvents\MessageLogged::class && ! $this->recordLogs) {
                continue;
            }

            $dispatcher->listen($eventName, [$this, $handler]);
        }

        if ($this->recordSqlTransactions) {
            $dispatcher->listen(DatabaseEvents\TransactionBeginning::class, [$this, 'transactionEvent']);
            $dispatcher->listen(DatabaseEvents\TransactionCommitted::class, [$this, 'transactionEvent']);
            $dispatcher->listen(DatabaseEvents\TransactionRolledBack::class, [$this, 'transactionEvent']);
        }
    }

    /**
     * Attach all authentication event handlers.
     */
    public function subscribeAuthEvents(Dispatcher $dispatcher): void
    {
        foreach (static::$authEventHandlerMap as $eventName => $handler) {
            $dispatcher->listen($eventName, [$this, $handler]);
        }
    }

    /**
     * Pass through the event and capture any errors.
     */
    public function __call(string $method, array $arguments): void
    {
        $handlerMethod = "{$method}Handler";

        if (! method_exists($this, $handlerMethod)) {
            throw new RuntimeException("Missing event handler: {$handlerMethod}");
        }

        try {
            $this->{$handlerMethod}(...$arguments);
        } catch (Exception) {
            // Ignore
        }
    }

    /**
     * Handle a route matched event.
     */
    protected function routeMatchedHandler(RoutingEvents\RouteMatched $match): void
    {
        Middleware::signalRouteWasMatched();

        [$routeName] = Integration::extractNameAndSourceForRoute($match->route);

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_NAVIGATION,
            'route',
            $routeName
        ));

        Integration::setTransaction($routeName);
    }

    /**
     * Handle a SQL query executed event.
     */
    protected function queryExecutedHandler(DatabaseEvents\QueryExecuted $query): void
    {
        $data = ['connectionName' => $query->connectionName];

        if ($query->time !== null) {
            $data['executionTimeMs'] = $query->time;
        }

        if ($this->recordSqlBindings) {
            $data['bindings'] = $query->bindings;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'db.sql.query',
            $query->sql,
            $data
        ));
    }

    /**
     * Handle a SQL transaction event (begin, commit, rollback).
     */
    protected function transactionEventHandler(
        DatabaseEvents\TransactionBeginning|DatabaseEvents\TransactionCommitted|DatabaseEvents\TransactionRolledBack $event,
    ): void {
        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'db.sql.transaction',
            $event::class,
            ['connectionName' => $event->connectionName],
        ));
    }

    /**
     * Handle a message logged event.
     */
    protected function messageLoggedHandler(LogEvents\MessageLogged $logEntry): void
    {
        Integration::addBreadcrumb(new Breadcrumb(
            $this->logLevelToBreadcrumbLevel($logEntry->level),
            Breadcrumb::TYPE_DEFAULT,
            'log.' . $logEntry->level,
            $logEntry->message,
            $logEntry->context
        ));
    }

    /**
     * Handle an authenticated event.
     */
    protected function authenticatedHandler(AuthEvents\Authenticated $event): void
    {
        $this->configureUserScopeFromModel($event->user);
    }

    /**
     * Handle a Sanctum token authenticated event.
     */
    protected function sanctumTokenAuthenticatedHandler(Sanctum\TokenAuthenticated $event): void
    {
        $this->configureUserScopeFromModel($event->token->tokenable);
    }

    /**
     * Configure the user scope with user data and values from the HTTP request.
     */
    private function configureUserScopeFromModel(mixed $authUser): void
    {
        $userData = [];

        // If the user is an Eloquent model we try to extract some common fields from it
        if ($authUser instanceof Model) {
            $email = null;

            if ($this->modelHasAttribute($authUser, 'email')) {
                $email = $authUser->getAttribute('email');

                if ($email !== null) {
                    $email = (string) $email;
                }
            } elseif ($this->modelHasAttribute($authUser, 'mail')) {
                $email = $authUser->getAttribute('mail');

                if ($email !== null) {
                    $email = (string) $email;
                }
            }

            $username = $this->modelHasAttribute($authUser, 'username')
                ? (string) $authUser->getAttribute('username')
                : null;

            $userData = [
                'id' => $authUser instanceof Authenticatable
                    ? $authUser->getAuthIdentifier()
                    : $authUser->getKey(),
                'email' => $email,
                'username' => $username,
            ];
        }

        try {
            $request = $this->container->make('request');
            $ipAddress = $request->ip();

            if ($ipAddress !== null) {
                $userData['ip_address'] = $ipAddress;
            }
        } catch (BindingResolutionException) {
            // If there is no request bound we cannot get the IP address from it
        }

        Integration::configureScope(static function (Scope $scope) use ($userData): void {
            $scope->setUser(array_filter($userData));
        });
    }

    /**
     * Check if a model has a given attribute.
     */
    private function modelHasAttribute(Model $model, string $key): bool
    {
        return array_key_exists($key, $model->getAttributes())
            || $model->hasGetMutator($key)
            || $model->hasAttributeMutator($key);
    }

    /**
     * Translate common log levels to Sentry breadcrumb levels.
     */
    private function logLevelToBreadcrumbLevel(string $level): string
    {
        return match (strtolower($level)) {
            'debug' => Breadcrumb::LEVEL_DEBUG,
            'warning' => Breadcrumb::LEVEL_WARNING,
            'error' => Breadcrumb::LEVEL_ERROR,
            'critical', 'alert', 'emergency' => Breadcrumb::LEVEL_FATAL,
            default => Breadcrumb::LEVEL_INFO,
        };
    }
}
