<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Exception;
// @TODO: Uncomment once Hypervel\Auth\Events is ported
// use Hypervel\Auth\Events as AuthEvents;
// use Hypervel\Contracts\Auth\Authenticatable;
// use Hypervel\Contracts\Container\BindingResolutionException;
// use Hypervel\Database\Eloquent\Model;
// use Hypervel\Http\Request;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Log\Events as LogEvents;
use Hypervel\Routing\Events as RoutingEvents;
use Hypervel\Sentry\Integrations\Integration;
use Hypervel\Sentry\Tracing\Middleware;
use RuntimeException;
use Sentry\Breadcrumb;

// @TODO: Uncomment once Hypervel\Auth\Events is ported
// use Sentry\State\Scope;

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
    ];

    // @TODO: Uncomment once Hypervel\Auth\Events is ported
    // /**
    //  * Map authentication event handlers to events.
    //  *
    //  * @var array<class-string, string>
    //  */
    // protected static array $authEventHandlerMap = [
    //     AuthEvents\Authenticated::class => 'authenticated',
    // ];

    private readonly bool $recordLogs;

    /**
     * Create a new event handler instance.
     */
    public function __construct(
        // @TODO: Restore once Hypervel\Auth\Events is ported (needed for configureUserScopeFromModel)
        // private readonly Container $container,
        array $config,
    ) {
        $this->recordLogs = ($config['breadcrumbs']['logs'] ?? true) === true;
    }

    /**
     * Attach all event handlers.
     */
    public function subscribe(Dispatcher $dispatcher): void
    {
        foreach (static::$eventHandlerMap as $eventName => $handler) {
            $dispatcher->listen($eventName, [$this, $handler]);
        }
    }

    // @TODO: Uncomment once Hypervel\Auth\Events is ported
    // /**
    //  * Attach all authentication event handlers.
    //  */
    // public function subscribeAuthEvents(Dispatcher $dispatcher): void
    // {
    //     foreach (static::$authEventHandlerMap as $eventName => $handler) {
    //         $dispatcher->listen($eventName, [$this, $handler]);
    //     }
    // }

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
     * Handle a message logged event.
     */
    protected function messageLoggedHandler(LogEvents\MessageLogged $logEntry): void
    {
        if (! $this->recordLogs) {
            return;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            $this->logLevelToBreadcrumbLevel($logEntry->level),
            Breadcrumb::TYPE_DEFAULT,
            'log.' . $logEntry->level,
            $logEntry->message,
            $logEntry->context
        ));
    }

    // @TODO: Uncomment once Hypervel\Auth\Events is ported
    // /**
    //  * Handle an authenticated event.
    //  */
    // protected function authenticatedHandler(AuthEvents\Authenticated $event): void
    // {
    //     $this->configureUserScopeFromModel($event->user);
    // }
    //
    // /**
    //  * Configure the user scope with user data and values from the HTTP request.
    //  */
    // private function configureUserScopeFromModel(mixed $authUser): void
    // {
    //     $userData = [];
    //
    //     // If the user is an Eloquent model we try to extract some common fields from it
    //     if ($authUser instanceof Model) {
    //         $email = null;
    //
    //         if ($this->modelHasAttribute($authUser, 'email')) {
    //             $email = $authUser->getAttribute('email');
    //         } elseif ($this->modelHasAttribute($authUser, 'mail')) {
    //             $email = $authUser->getAttribute('mail');
    //         }
    //
    //         $username = $this->modelHasAttribute($authUser, 'username')
    //             ? (string) $authUser->getAttribute('username')
    //             : null;
    //
    //         $userData = [
    //             'id' => $authUser instanceof Authenticatable
    //                 ? $authUser->getAuthIdentifier()
    //                 : $authUser->getKey(),
    //             'email' => $email,
    //             'username' => $username,
    //         ];
    //     }
    //
    //     try {
    //         /** @var Request $request */
    //         $request = $this->container->make('request');
    //
    //         if ($request instanceof Request) {
    //             $ipAddress = $request->ip();
    //
    //             if ($ipAddress !== null) {
    //                 $userData['ip_address'] = $ipAddress;
    //             }
    //         }
    //     } catch (BindingResolutionException) {
    //         // If there is no request bound we cannot get the IP address from it
    //     }
    //
    //     Integration::configureScope(static function (Scope $scope) use ($userData): void {
    //         $scope->setUser(array_filter($userData));
    //     });
    // }
    //
    // /**
    //  * Check if a model has a given attribute.
    //  */
    // private function modelHasAttribute(Model $model, string $key): bool
    // {
    //     return array_key_exists($key, $model->getAttributes())
    //         || $model->hasGetMutator($key)
    //         || (method_exists($model, 'hasAttributeMutator') && $model->hasAttributeMutator($key));
    // }

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
