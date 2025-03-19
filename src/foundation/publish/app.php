<?php

declare(strict_types=1);

use Psr\Log\LogLevel;

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Hypervel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set "APP_ENV" in your ".env" file.
    |
    */

    'env' => app()->environment(),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    | Set "APP_DEBUG" in your ".env" file.
    |
    */

    'debug' => app()->hasDebugModeEnabled(),

    /*
    |--------------------------------------------------------------------------
    | Cacheable Flag for Annotations Scanning
    |--------------------------------------------------------------------------
    |
    | Enabling this option will cache the annotations scanning result. It
    | can boost the performance of the framework initialization.
    | Please disable it in the development environment.
    |
    */
    'scan_cacheable' => env('SCAN_CACHEABLE', false),

    /*
    |--------------------------------------------------------------------------
    | Log Levels for StdoutLogger
    |--------------------------------------------------------------------------
    |
    | This value only determines the log levels that are written to the stdout logger.
    | It does not affect the log levels that are written to the other loggers.
    |
    */
    'stdout_log_level' => [
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        // LogLevel::DEBUG,
        LogLevel::EMERGENCY,
        LogLevel::ERROR,
        LogLevel::INFO,
        LogLevel::NOTICE,
        LogLevel::WARNING,
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode for Command Errors
    |--------------------------------------------------------------------------
    |
    | This value determines whether the stack strace will be displayed
    | when errors occur in the command line.
    |
    */

    'command_debug_enabled' => env('COMMAND_DEBUG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'providers' => [
        Hypervel\Foundation\Providers\FoundationServiceProvider::class,
        Hypervel\Foundation\Providers\FormRequestServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        App\Providers\AppServiceProvider::class,
        App\Providers\EventServiceProvider::class,
    ],

    'aliases' => [
        'App' => Hypervel\Support\Facades\App::class,
        'Artisan' => Hypervel\Support\Facades\Artisan::class,
        'Cache' => Hypervel\Support\Facades\Cache::class,
        'Config' => Hypervel\Support\Facades\Config::class,
        'Cookie' => Hypervel\Support\Facades\Cookie::class,
        'Crypt' => Hypervel\Support\Facades\Crypt::class,
        'DB' => Hypervel\Support\Facades\DB::class,
        'File' => Hypervel\Support\Facades\File::class,
        'Log' => Hypervel\Support\Facades\Log::class,
        'Request' => Hypervel\Support\Facades\Request::class,
        'Response' => Hypervel\Support\Facades\Response::class,
        'Translator' => Hypervel\Support\Facades\Translator::class,
        'Validator' => Hypervel\Support\Facades\Validator::class,
        'JWT' => Hypervel\Support\Facades\JWT::class,
        'Auth' => Hypervel\Support\Facades\Auth::class,
        'Hash' => Hypervel\Support\Facades\Hash::class,
        'Environment' => Hypervel\Support\Facades\Environment::class,
        'Schedule' => Hypervel\Support\Facades\Schedule::class,
    ],
];
