# Installation

- [Creating a Hypervel Application](#creating-a-hypervel-application)
    - [Requirements](#requirements)
    - [Creating an Application](#creating-an-application)
    - [Watching for Changes](#watching-for-changes)
    - [Starter Kits](#starter-kits)
- [Initial Configuration](#initial-configuration)
    - [Environment Based Configuration](#environment-based-configuration)
    - [Databases and Migrations](#databases-and-migrations)
    - [Directory Configuration](#directory-configuration)
- [IDE Support](#ide-support)
- [Hypervel and AI](#hypervel-and-ai)
    - [Installing Hypervel Boost](#installing-hypervel-boost)
- [Next Steps](#next-steps)
    - [Hypervel the Full Stack Framework](#hypervel-the-fullstack-framework)
    - [Hypervel the API Backend](#hypervel-the-api-backend)

<a name="creating-a-hypervel-application"></a>
## Creating a Hypervel Application

<a name="requirements"></a>
### Requirements

Before creating your first Hypervel application, make sure that your local machine has [PHP](https://php.net), [Composer](https://getcomposer.org), and the Swoole PHP extension installed. In addition, you should install either [Node and NPM](https://nodejs.org) or [Bun](https://bun.sh/) so that you can compile your application's frontend assets.

The Hypervel framework has a few system requirements:

<div class="content-list" markdown="1">

- PHP >= 8.4
- Composer
- Fileinfo PHP Extension
- Filter PHP Extension
- Hash PHP Extension
- Intl PHP Extension
- JSON PHP Extension
- Mbstring PHP Extension
- OpenSSL PHP Extension
- PCNTL PHP Extension
- PDO PHP Extension
- POSIX PHP Extension
- Session PHP Extension
- Swoole PHP Extension >= 6.2
- Tokenizer PHP Extension

</div>

If your application uses Redis for cache, queues, sessions, or broadcasting, you should also install the Redis PHP extension 6.1 or higher.

You may install Swoole using PECL:

```shell
pecl install swoole
```

If you develop on macOS, you may also install Swoole via Homebrew. Replace `8.4` with your installed PHP version:

```shell
brew tap shivammathur/extensions
brew install shivammathur/extensions/swoole@8.4
```

You should also ensure that `swoole.use_shortname` is disabled in your `php.ini` file:

```ini
swoole.use_shortname=Off
```

Hypervel will not start while Swoole short function names are enabled. Disabling them ensures Hypervel's namespaced coroutine helpers and exception handling work correctly.

<a name="incompatible-extensions"></a>
#### Incompatible Extensions

Because Hypervel is based on Swoole's coroutine functionality, some extensions are incompatible with the coroutine runtime. The following extensions are currently incompatible:

<div class="content-list" markdown="1">

- xhprof
- blackfire
- trace
- uopz

</div>

Xdebug support depends on your PHP and Swoole versions. If you experience coroutine or server issues while debugging, disable Xdebug and retry the request.

<a name="creating-an-application"></a>
### Creating an Application

After you have installed PHP, Composer, and Swoole, you're ready to create a new Hypervel application using Composer's `create-project` command:

```shell
composer create-project hypervel/hypervel example-app
```

Once the application has been created, you can start Hypervel's local development server using the `serve` Artisan command:

```shell
cd example-app
php artisan serve
```

Once you have started the development server, your application will be accessible in your web browser at [http://localhost:9501](http://localhost:9501). The server host, port, worker count, and other Swoole options may be configured in your application's `config/server.php` file.

<a name="watching-for-changes"></a>
### Watching for Changes

Because Hypervel runs your application in a long-lived process, you should restart the server after making code changes. During development, you may use the `watch` command instead of `serve` to start the server and restart it automatically when files change:

```shell
php artisan watch
```

You may customize the watched paths in your application's `config/watcher.php` file, or pass extra paths using the `--path` option:

```shell
php artisan watch --path=routes --path=database/**/*.php
```

If your application uses frontend assets, install and build them using your JavaScript package manager:

```shell
npm install
npm run build
```

Of course, you may also want to [configure a database](#databases-and-migrations).

<a name="starter-kits"></a>
### Starter Kits

If you would like a head start when developing your Hypervel application, consider using one of our [starter kits](/docs/{{version}}/starter-kits). Hypervel starter kits provide backend and frontend authentication scaffolding for your new application.

For a minimal Blade application, start with the standard Hypervel application skeleton:

```shell
composer create-project hypervel/hypervel example-app
```

If you would like to build your frontend using Inertia and React, use the React application starter kit:

```shell
composer create-project hypervel/react-starter-kit example-app
```

<a name="initial-configuration"></a>
## Initial Configuration

All of the configuration files for the Hypervel framework are stored in the `config` directory. Each option is documented, so feel free to look through the files and get familiar with the options available to you.

Hypervel needs almost no additional configuration out of the box. You are free to get started developing! However, you may wish to review the `config/app.php` and `config/server.php` files and their documentation. These files contain options such as your application's URL, locale, server host, server port, and worker count.

<a name="environment-based-configuration"></a>
### Environment Based Configuration

Since many of Hypervel's configuration option values may vary depending on whether your application is running on your local machine or on a production server, many important configuration values are defined using the `.env` file that exists at the root of your application.

Your `.env` file should not be committed to your application's source control, since each developer / server using your application could require a different environment configuration. Furthermore, this would be a security risk in the event an intruder gains access to your source control repository, since any sensitive credentials would be exposed.

> [!NOTE]
> For more information about the `.env` file and environment based configuration, check out the full [configuration documentation](/docs/{{version}}/configuration#environment-configuration).

<a name="databases-and-migrations"></a>
### Databases and Migrations

Now that you have created your Hypervel application, you probably want to store some data in a database. By default, your application's `.env` configuration file specifies that Hypervel will be interacting with an SQLite database.

During the creation of the application, Hypervel created a `database/database.sqlite` file for you, and ran the necessary migrations to create the application's database tables.

If you prefer to use another database driver such as MySQL or PostgreSQL, you can update your `.env` configuration file to use the appropriate database. For example, if you wish to use MySQL, update your `.env` configuration file's `DB_*` variables like so:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hypervel
DB_USERNAME=root
DB_PASSWORD=
```

If you choose to use a database other than SQLite, you will need to create the database and run your application's [database migrations](/docs/{{version}}/migrations):

```shell
php artisan migrate
```

<a name="directory-configuration"></a>
### Directory Configuration

Hypervel should be served by its Swoole-based application server. In production, a web server such as Nginx should serve your application's static files directly and proxy dynamic requests to the Hypervel server. You should not attempt to serve a Hypervel application directly out of a subdirectory of your web server's document root, as doing so could expose sensitive files present within your application.

<a name="ide-support"></a>
## IDE Support

You are free to use any code editor you wish when developing Hypervel applications. If you're looking for lightweight and extensible editors, [VS Code](https://code.visualstudio.com) or [Cursor](https://cursor.com) provide a great editing experience for PHP, JavaScript, and TypeScript projects.

For extensive and robust PHP support, take a look at [PhpStorm](https://www.jetbrains.com/phpstorm/), a JetBrains IDE. PhpStorm includes powerful code completion, refactoring, navigation, and debugging tools for PHP applications.

Hypervel's application skeleton includes the `swoole/ide-helper` package in development so IDEs can understand Swoole classes, constants, and functions.

<a name="hypervel-and-ai"></a>
## Hypervel and AI

[Hypervel Boost](https://github.com/hypervel/components/tree/main/src/boost) is a powerful tool that bridges the gap between AI coding agents and Hypervel applications. Boost provides AI agents with Hypervel-specific context, tools, and guidelines so they can generate more accurate, version-specific code that follows Hypervel conventions.

When you install Boost in your Hypervel application, AI agents gain access to specialized tools including the ability to know which packages you are using, query your database, search the Hypervel documentation, read browser logs, generate tests, and execute code via Tinker.

In addition, Boost gives AI agents access to vectorized Hypervel ecosystem documentation, specific to your installed package versions. This means agents can provide guidance targeted to the exact versions your project uses.

Boost also includes Hypervel-maintained AI guidelines that help agents to follow framework conventions, write appropriate tests, and avoid common pitfalls when generating Hypervel code.

<a name="installing-hypervel-boost"></a>
### Installing Hypervel Boost

Boost can be installed in Hypervel applications running PHP 8.4 or higher. To get started, install Boost as a development dependency:

```shell
composer require hypervel/boost --dev
```

Once installed, run the interactive installer:

```shell
php artisan boost:install
```

The installer will auto-detect your IDE and AI agents, allowing you to opt into the features that make sense for your project. Boost respects existing project conventions and does not force opinionated style rules by default.

> [!NOTE]
> To learn more about Boost, check out the [Hypervel Boost source on GitHub](https://github.com/hypervel/components/tree/main/src/boost).

<a name="adding-custom-ai-guidelines"></a>
#### Adding Custom AI Guidelines

To augment Hypervel Boost with your own custom AI guidelines, add `.blade.php` or `.md` files to your application's `.ai/guidelines/*` directory. These files will automatically be included with Hypervel Boost's guidelines when you run `boost:install`.

<a name="next-steps"></a>
## Next Steps

Now that you have created your Hypervel application, you may be wondering what to learn next. First, we strongly recommend becoming familiar with how Hypervel works by reading the following documentation:

<div class="content-list" markdown="1">

- [Request Lifecycle](/docs/{{version}}/lifecycle)
- [Configuration](/docs/{{version}}/configuration)
- [Directory Structure](/docs/{{version}}/structure)
- [Frontend](/docs/{{version}}/frontend)
- [Service Container](/docs/{{version}}/container)
- [Facades](/docs/{{version}}/facades)
- [Coroutines](/docs/{{version}}/coroutines)
- [Deployment](/docs/{{version}}/deployment)

</div>

How you want to use Hypervel will also dictate the next steps on your journey. There are a variety of ways to use Hypervel, and we'll explore two primary use cases for the framework below.

<a name="hypervel-the-fullstack-framework"></a>
### Hypervel the Full Stack Framework

Hypervel may serve as a full stack framework. By "full stack" framework we mean that you are going to use Hypervel to route requests to your application and render your frontend via [Blade templates](/docs/{{version}}/blade) or a single-page application hybrid technology like [Inertia](https://inertiajs.com). This is the most common way to use the Hypervel framework, and, in our opinion, the most productive way to use Hypervel.

If this is how you plan to use Hypervel, you may want to check out our documentation on [frontend development](/docs/{{version}}/frontend), [routing](/docs/{{version}}/routing), [views](/docs/{{version}}/views), or the [Eloquent ORM](/docs/{{version}}/eloquent). Hypervel includes first-class support for Blade and Inertia, and our React starter kit provides a fast path to a full-stack Inertia application.

If you are using Hypervel as a full stack framework, we also strongly encourage you to learn how to compile your application's CSS and JavaScript using [Vite](/docs/{{version}}/vite).

> [!NOTE]
> If you want to get a head start building your application, check out one of our official [application starter kits](/docs/{{version}}/starter-kits).

<a name="hypervel-the-api-backend"></a>
### Hypervel the API Backend

Hypervel may also serve as an API backend to a JavaScript single-page application, mobile application, or another service. In this context, you may use Hypervel to provide authentication, data storage / retrieval, queues, WebSocket services, scheduled jobs, notifications, and more.

If this is how you plan to use Hypervel, you may want to check out our documentation on [routing](/docs/{{version}}/routing), [Hypervel Sanctum](/docs/{{version}}/sanctum), [queues](/docs/{{version}}/queues), [broadcasting](/docs/{{version}}/broadcasting), and the [Eloquent ORM](/docs/{{version}}/eloquent).
