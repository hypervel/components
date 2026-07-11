# Configuration
[[toc]]

## Introduction

All of the configuration files for the Hypervel framework are stored in the `config` directory. Each option is documented, so feel free to look through the files and get familiar with the options available to you.

These configuration files allow you to configure things like your database connection information, your mail server information, as well as various other core configuration values such as your application URL and encryption key.

### The `about` Command

Hypervel can display an overview of your application's configuration, drivers, and environment via the `about` Artisan command.

```shell:no-line-numbers
php artisan about
```

If you're only interested in a particular section of the application overview output, you may filter for that section using the `--only` option:

```shell:no-line-numbers
php artisan about --only=environment
```

Or, to explore a specific configuration file's values in detail, you may use the `config:show` Artisan command:

```shell:no-line-numbers
php artisan config:show database
```

## Unsupported Recursive Config Resolution

Since Hypervel initializes the Configuration service at a very early stage in the framework's underlying mechanism, which differs from Laravel, the related configs are loaded into the framework at an extremely early phase.

At this point, **Config Instance** is not ready while reading config files. This means you shouldn't refer to other config values in the config files.

For example, you will get errors with the following config declaration:

```php
return [
    'foo' => config('bar.title'),
];
```

::: warning
Some special config files will be loaded by Hypervel before the app container is ready, such as `config/app.php`, `config/dependencies.php`, `config/annotations.php`. You shouldn't call any functions that will require app container in these config files.
:::

## Environment Configuration

It is often helpful to have different configuration values based on the environment where the application is running. For example, you may wish to use a different cache driver locally than you do on your production server.

To make this a cinch, Hypervel utilizes the [DotEnv](https://github.com/vlucas/phpdotenv) PHP library. In a fresh Hypervel installation, the root directory of your application will contain a `.env.example` file that defines many common environment variables. During the Hypervel installation process, this file will automatically be copied to `.env`.

Hypervel's default `.env` file contains some common configuration values that may differ based on whether your application is running locally or on a production web server. These values are then read by the configuration files within the `config` directory using Hypervel's `env` function.

If you are developing with a team, you may wish to continue including and updating the `.env.example` file with your application. By putting placeholder values in the example configuration file, other developers on your team can clearly see which environment variables are needed to run your application.

::: note
Any variable in your `.env` file can be overridden by external environment variables such as server-level or system-level environment variables.
:::

#### Environment File Security

Your `.env` file should not be committed to your application's source control, since each developer / server using your application could require a different environment configuration. Furthermore, this would be a security risk in the event an intruder gains access to your source control repository, since any sensitive credentials would get exposed.

### Environment Variable Types

All variables in your `.env` files are typically parsed as strings, so some reserved values have been created to allow you to return a wider range of types from the `env()` function:

<div class="overflow-auto">

| `.env` Value | `env()` Value |
| ------------ | ------------- |
| true         | (bool) true   |
| (true)       | (bool) true   |
| false        | (bool) false  |
| (false)      | (bool) false  |
| empty        | (string) ''   |
| (empty)      | (string) ''   |
| null         | (null) null   |
| (null)       | (null) null   |

</div>

If you need to define an environment variable with a value that contains spaces, you may do so by enclosing the value in double quotes:

```ini
APP_NAME="My Application"
```

### Retrieving Environment Configuration

You can use the `env` function to retrieve values from these variables in your configuration files. In fact, if you review the Hypervel configuration files, you will notice many of the options are already using this function:

```php
'debug' => env('APP_DEBUG', false),
```

The second value passed to the `env` function is the "default value". This value will be returned if no environment variable exists for the given key.

### Determining the Current Environment

The current application environment is determined via the `APP_ENV` variable from your `.env` file. You may access this value via the `environment` method on the `App` [facade](/docs/facades):

```php
use Hypervel\Support\Facades\App;

$environment = App::environment();
```

You may also pass arguments to the `environment` method to determine if the environment matches a given value. The method will return `true` if the environment matches any of the given values:

```php
if (App::environment('local')) {
    // The environment is local
}

if (App::environment(['local', 'staging'])) {
    // The environment is either local OR staging...
}
```

::: note
The current application environment detection can be overridden by defining a server-level `APP_ENV` environment variable.
:::

## Accessing Configuration Values

You may easily access your configuration values using the `Config` facade or global `config` function from anywhere in your application. The configuration values may be accessed using "dot" syntax, which includes the name of the file and option you wish to access. A default value may also be specified and will be returned if the configuration option does not exist:

```php
use Hypervel\Support\Facades\Config;

$value = Config::get('app.timezone');

$value = config('app.timezone');

// Retrieve a default value if the configuration value does not exist...
$value = config('app.timezone', 'Asia/Seoul');
```

To set configuration values at runtime, you may invoke the `Config` facade's `set` method or pass an array to the `config` function:

```php
Config::set('app.timezone', 'America/Chicago');

config(['app.timezone' => 'America/Chicago']);
```

To assist with static analysis, the `Config` facade also provides typed configuration retrieval methods. If the retrieved configuration value does not match the expected type, an exception will be thrown:

```php
Config::string('config-key');
Config::integer('config-key');
Config::float('config-key');
Config::boolean('config-key');
Config::array('config-key');
```

## Configuration Publishing

Most of Hypervel's configuration files are already published in your application's `config` directory; however, certain configuration files like `cors.php` and `view.php` are not published by default, as most applications will never need to modify them.

However, you may use the `vendor:publish` Artisan command to publish packages' assets including configuration files that are not published by default:

```shell:no-line-numbers
php artisan vendor:publish package

php artisan vendor:publish --all
```

## Debug Mode

The `debug` option in your `config/app.php` configuration file determines how much information about an error is actually displayed to the user. By default, this option is set to respect the value of the `APP_DEBUG` environment variable, which is stored in your `.env` file.

::: warning
For local development, you should set the `APP_DEBUG` environment variable to `true`. **In your production environment, this value should always be `false`. If the variable is set to `true` in production, you risk exposing sensitive configuration values to your application's end users.**
:::
