<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Closure;
use Exception;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Js;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class Horizon
{
    /**
     * The callback that should be used to authenticate Horizon users.
     */
    public static ?Closure $authUsing = null;

    /**
     * The Slack notifications webhook URL.
     */
    public static ?string $slackWebhookUrl = null;

    /**
     * The Slack notifications channel.
     */
    public static ?string $slackChannel = null;

    /**
     * The email address for notifications.
     */
    public static ?string $email = null;

    /**
     * Indicates if Horizon should use the dark theme.
     *
     * @deprecated
     */
    public static bool $useDarkTheme = false;

    /**
     * The database configuration methods.
     */
    public static array $databases = [
        'Jobs', 'Supervisors', 'CommandQueue', 'Tags',
        'Metrics', 'Locks', 'Processes',
    ];

    /**
     * Determine if the given request can access the Horizon dashboard.
     */
    public static function check(?ServerRequestInterface $request): bool
    {
        return (static::$authUsing ?: function () {
            return app()->environment('local');
        })($request);
    }

    /**
     * Set the callback that should be used to authenticate Horizon users.
     */
    public static function auth(Closure $callback): static
    {
        static::$authUsing = $callback;

        return new static();
    }

    /**
     * Configure the Redis databases that will store Horizon data.
     *
     * @throws Exception
     */
    public static function use(string $connection): void
    {
        if (! is_null($config = config("database.redis.clusters.{$connection}.0"))) {
            config(["database.redis.{$connection}" => $config]);
        } elseif (is_null($config = config("database.redis.{$connection}"))) {
            throw new Exception("Redis connection [{$connection}] has not been configured.");
        }

        $config['options']['prefix'] = config('horizon.prefix') ?: 'horizon:';

        config(['redis.horizon' => $config]);
    }

    /**
     * Get the CSS for the Horizon dashboard.
     */
    public static function css(): HtmlString
    {
        if (($light = @file_get_contents(__DIR__ . '/../dist/styles.css')) === false) {
            throw new RuntimeException('Unable to load the Horizon dashboard light CSS.');
        }

        if (($dark = @file_get_contents(__DIR__ . '/../dist/styles-dark.css')) === false) {
            throw new RuntimeException('Unable to load the Horizon dashboard dark CSS.');
        }

        if (($app = @file_get_contents(__DIR__ . '/../dist/app.css')) === false) {
            throw new RuntimeException('Unable to load the Horizon dashboard CSS.');
        }

        return new HtmlString(<<<HTML
            <style data-scheme="light">{$light}</style>
            <style data-scheme="dark">{$dark}</style>
            <style>{$app}</style>
            HTML);
    }

    /**
     * Get the JS for the Horizon dashboard.
     */
    public static function js(): HtmlString
    {
        if (($js = @file_get_contents(__DIR__ . '/../dist/app.js')) === false) {
            throw new RuntimeException('Unable to load the Horizon dashboard JavaScript.');
        }

        $horizon = Js::from(static::scriptVariables());

        return new HtmlString(<<<HTML
            <script type="module">
                window.Horizon = {$horizon};
                {$js}
            </script>
            HTML);
    }

    /**
     * Specifies that Horizon should use the dark theme.
     *
     * @deprecated
     */
    public static function night(): static
    {
        static::$useDarkTheme = true;

        return new static();
    }

    /**
     * Get the default JavaScript variables for Horizon.
     */
    public static function scriptVariables(): array
    {
        return [
            'path' => config('horizon.path'),
            'proxy_path' => config('horizon.proxy_path', ''),
        ];
    }

    /**
     * Specify the email address to which email notifications should be routed.
     */
    public static function routeMailNotificationsTo(string $email): static
    {
        static::$email = $email;

        return new static();
    }

    /**
     * Specify the webhook URL and channel to which Slack notifications should be routed.
     */
    public static function routeSlackNotificationsTo(string $url, ?string $channel = null): static
    {
        static::$slackWebhookUrl = $url;
        static::$slackChannel = $channel;

        return new static();
    }
}
