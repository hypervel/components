<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Closure;
use Exception;
use Hypervel\Context\CoroutineContext;
use Hypervel\Foundation\DevCommands;
use Hypervel\Http\Request;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Js;
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
     * The SMS notifications phone number.
     */
    public static ?string $smsNumber = null;

    /**
     * The email address for notifications.
     */
    public static ?string $email = null;

    /**
     * The database configuration methods.
     */
    public static array $databases = [
        'Jobs', 'Supervisors', 'CommandQueue', 'Tags',
        'Metrics', 'Locks', 'Processes',
    ];

    /**
     * The context key for Horizon's CSP nonce attribute.
     */
    protected const CSP_NONCE_CONTEXT_KEY = '__horizon.csp_nonce';

    /**
     * Determine if the given request can access the Horizon dashboard.
     */
    public static function check(?Request $request): bool
    {
        return (static::$authUsing ?: function () {
            return app()->environment('local');
        })($request);
    }

    /**
     * Set the callback that should be used to authenticate Horizon users.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs on every Horizon dashboard request across all
     * coroutines.
     */
    public static function auth(Closure $callback): static
    {
        static::$authUsing = $callback;

        return new static;
    }

    /**
     * Configure the Redis databases that will store Horizon data.
     *
     * Boot-only. Mutates process-global config (database.redis.horizon);
     * per-request use races across coroutines.
     *
     * @throws Exception
     */
    public static function use(string $connection): void
    {
        $config = config("database.redis.{$connection}");

        if (! is_array($config)) {
            throw new Exception("Redis connection [{$connection}] has not been configured.");
        }

        $prefix = config()->string('horizon.prefix');

        if (($config['cluster']['enable'] ?? false)
            && ! RedisConnection::hasHashTag($prefix)) {
            $prefix = '{' . $prefix . '}';
        }

        // RedisConfig gives the top-level connection prefix final precedence.
        $config['prefix'] = $prefix;
        $config['options']['prefix'] = $prefix;

        config([
            'horizon.prefix' => $prefix,
            'database.redis.horizon' => $config,
        ]);
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

        $nonceAttribute = CoroutineContext::get(self::CSP_NONCE_CONTEXT_KEY, '');

        return new HtmlString(<<<HTML
            <style data-scheme="light"{$nonceAttribute}>{$light}</style>
            <style data-scheme="dark"{$nonceAttribute}>{$dark}</style>
            <style{$nonceAttribute}>{$app}</style>
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

        $nonceAttribute = CoroutineContext::get(self::CSP_NONCE_CONTEXT_KEY, '');

        return new HtmlString(<<<HTML
            <script type="module"{$nonceAttribute}>
                window.Horizon = {$horizon};
                {$js}
            </script>
            HTML);
    }

    // REMOVED: Deprecated Horizon::night() theme mutator.

    /**
     * Get the default JavaScript variables for Horizon.
     */
    public static function scriptVariables(): array
    {
        return [
            'path' => config()->string('horizon.path'),
            'proxy_path' => config()->string('horizon.proxy_path'),
        ];
    }

    /**
     * Specify the email address to which email notifications should be routed.
     *
     * Boot-only. The address persists in a static property for the worker
     * lifetime and is used by every Horizon notification dispatch.
     */
    public static function routeMailNotificationsTo(string $email): static
    {
        static::$email = $email;

        return new static;
    }

    /**
     * Specify the webhook URL and channel to which Slack notifications should be routed.
     *
     * Boot-only. The URL and channel persist in static properties for the
     * worker lifetime and are used by every Horizon notification dispatch.
     */
    public static function routeSlackNotificationsTo(string $url, ?string $channel = null): static
    {
        static::$slackWebhookUrl = $url;
        static::$slackChannel = $channel;

        return new static;
    }

    /**
     * Specify the phone number to which SMS notifications should be routed.
     *
     * Boot-only. The number persists in a static property for the worker
     * lifetime and is used by every Horizon notification dispatch.
     */
    public static function routeSmsNotificationsTo(string $number): static
    {
        static::$smsNumber = $number;

        return new static;
    }

    /**
     * Set the CSP nonce to use for style and script tags.
     *
     * Call this from request middleware so the nonce is isolated to the
     * current request coroutine.
     */
    public static function cspNonce(string $nonce): static
    {
        CoroutineContext::set(
            self::CSP_NONCE_CONTEXT_KEY,
            ' nonce="' . $nonce . '"',
        );

        return new static;
    }

    /**
     * Register the Horizon development commands.
     *
     * Boot-only. The registrations persist for the worker lifetime and affect
     * every subsequent development command invocation.
     */
    public static function registerDevCommands(): void
    {
        DevCommands::artisan('horizon', 'horizon');
        DevCommands::except('queue');
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$authUsing = null;
        static::$slackWebhookUrl = null;
        static::$slackChannel = null;
        static::$smsNumber = null;
        static::$email = null;
    }
}
