# Artisan Console
[[toc]]

## Introduction

Artisan is the command line interface included with Hypervel. Artisan exists at the root of your application as the `artisan` script and provides a number of helpful commands that can assist you while you build your application. To view a list of all available Artisan commands, you may use the `list` command:

```shell:no-line-numbers
php artisan list
```

Every command also includes a "help" screen which displays and describes the command's available arguments and options. To view a help screen, precede the name of the command with `help`:

```shell:no-line-numbers
php artisan help migrate
```

### Tinker (REPL)

Hypervel Tinker is a powerful REPL for the Hypervel framework. Powered by the [PsySH](https://github.com/bobthecow/psysh) package.

#### Installation

All Hypervel applications include Tinker by default. However, you may install Tinker using Composer if you have previously removed it from your application:

```shell:no-line-numbers
composer require friendsofhyperf/tinker
```

#### Usage

Tinker allows you to interact with your entire Hypervel application on the command line, including your Eloquent models, jobs, events, and more. To enter the Tinker environment, run the `tinker` Artisan command:

```shell:no-line-numbers
php artisan tinker
```

You can publish Tinker's configuration file using the `vendor:publish` command:

```shell:no-line-numbers
php artisan vendor:publish friendsofhyperf/tinker
```

#### Command Allow List

Tinker utilizes an "allow" list to determine which Artisan commands are allowed to be run within its shell. By default, you may run the `migrate`command. If you would like to allow more commands you may add them to the `commands` array in your `tinker.php` configuration file:

```php
'commands' => [
    // App\Console\Commands\ExampleCommand::class,
],
```

#### Classes That Should Not Be Aliased

Typically, Tinker automatically aliases classes as you interact with them in Tinker. However, you may wish to never alias some classes. You may accomplish this by listing the classes in the `dont_alias` array of your `tinker.php` configuration file:

```php
'dont_alias' => [
    App\Models\User::class,
],
```

## Writing Commands

In addition to the commands provided with Artisan, you may build your own custom commands. Commands are typically stored in the `app/Console/Commands` directory; however, you are free to choose your own storage location as long as your commands can be loaded by Composer.

### Generating Commands

To create a new command, you may use the `make:command` Artisan command. This command will create a new command class in the `app/Console/Commands` directory. Don't worry if this directory does not exist in your application - it will be created the first time you run the `make:command` Artisan command:

```shell:no-line-numbers
php artisan make:command SendEmails
```

### Command Structure

After generating your command, you should define appropriate values for the `signature` and `description` properties of the class. These properties will be used when displaying your command on the `list` screen. The `signature` property also allows you to define [your command's input expectations](#defining-input-expectations). The `handle` method will be called when your command is executed. You may place your command logic in this method.

Let's take a look at an example command. Note that we are able to request any dependencies we need via the command's `handle` method. The Hypervel [service container](/docs/container) will automatically inject all dependencies that are type-hinted in this method's signature:

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DripEmailer;
use Hypervel\Console\Command;

class SendEmails extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'mail:send {user}';

    /**
     * The console command description.
     */
    protected string $description = 'Send a marketing email to a user';

    /**
     * Execute the console command.
     */
    public function handle(DripEmailer $drip): void
    {
        $drip->send(User::find($this->argument('user')));
    }
}
```

::: tip
For greater code reuse, it is good practice to keep your console commands light and let them defer to application services to accomplish their tasks. In the example above, note that we inject a service class to do the "heavy lifting" of sending the e-mails.
:::

### Closure Commands

Closure based commands provide an alternative to defining console commands as classes. In the same way that route closures are an alternative to controllers, think of command closures as an alternative to command classes. Within the `commands` method of your `app/Console/Kernel.php` file, Hypervel loads the `routes/console.php` file:

```php
/**
 * Register the closure based commands for the application.
 */
protected function commands(): void
{
    require base_path('routes/console.php');
}
```

Even though this file does not define HTTP routes, it defines console based entry points (routes) into your application. Within this file, you may define all of your closure based console commands using the `Artisan::command` method. The `command` method accepts two arguments: the [command signature](#defining-input-expectations) and a closure which receives the command's arguments and options:

```php
Artisan::command('mail:send {user}', function (string $user) {
    $this->info("Sending email to: {$user}!");
});
```

The closure is bound to the underlying command instance, so you have full access to all of the helper methods you would typically be able to access on a full command class.

#### Type-Hinting Dependencies

In addition to receiving your command's arguments and options, command closures may also type-hint additional dependencies that you would like resolved out of the [service container](/docs/container):

```php
use App\Models\User;
use App\Support\DripEmailer;

Artisan::command('mail:send {user}', function (DripEmailer $drip, string $user) {
    $drip->send(User::find($user));
});
```

#### Closure Command Descriptions

When defining a closure based command, you may use the `describe` method to add a description to the command. This description will be displayed when you run the `php artisan list` or `php artisan help` commands:

```php
Artisan::command('mail:send {user}', function (string $user) {
    // ...
})->describe('Send a marketing email to a user');
```

## Defining Input Expectations

When writing console commands, it is common to gather input from the user through arguments or options. Hypervel makes it very convenient to define the input you expect from the user using the `signature` property on your commands. The `signature` property allows you to define the name, arguments, and options for the command in a single, expressive, route-like syntax.

### Arguments

All user supplied arguments and options are wrapped in curly braces. In the following example, the command defines one required argument: `user`:

```php
/**
 * The name and signature of the console command.
 *
 * @var null|string
 */
protected ?string $signature = 'mail:send {user}';
```

You may also make arguments optional or define default values for arguments:

```php
// Optional argument...
'mail:send {user?}'

// Optional argument with default value...
'mail:send {user=foo}'
```

### Options

Options, like arguments, are another form of user input. Options are prefixed by two hyphens (`--`) when they are provided via the command line. There are two types of options: those that receive a value and those that don't. Options that don't receive a value serve as a boolean "switch". Let's take a look at an example of this type of option:

```php
/**
 * The name and signature of the console command.
 *
 * @var null|string
 */
protected ?string $signature = 'mail:send {user} {--queue}';
```

In this example, the `--queue` switch may be specified when calling the Artisan command. If the `--queue` switch is passed, the value of the option will be `true`. Otherwise, the value will be `false`:

```shell:no-line-numbers
php artisan mail:send 1 --queue
```

#### Options With Values

Next, let's take a look at an option that expects a value. If the user must specify a value for an option, you should suffix the option name with a `=` sign:

```php
/**
 * The name and signature of the console command.
 *
 * @var null|string
 */
protected ?string $signature = 'mail:send {user} {--queue=}';
```

In this example, the user may pass a value for the option like so. If the option is not specified when invoking the command, its value will be `null`:

```shell:no-line-numbers
php artisan mail:send 1 --queue=default
```

You may assign default values to options by specifying the default value after the option name. If no option value is passed by the user, the default value will be used:

```php
'mail:send {user} {--queue=default}'
```

#### Option Shortcuts

To assign a shortcut when defining an option, you may specify it before the option name and use the `|` character as a delimiter to separate the shortcut from the full option name:

```php
'mail:send {user} {--Q|queue}'
```

When invoking the command on your terminal, option shortcuts should be prefixed with a single hyphen and no `=` character should be included when specifying a value for the option:

```shell:no-line-numbers
php artisan mail:send 1 -Qdefault
```

### Input Arrays

If you would like to define arguments or options to expect multiple input values, you may use the `*` character. First, let's take a look at an example that specifies such an argument:

```php
'mail:send {user*}'
```

When calling this method, the `user` arguments may be passed in order to the command line. For example, the following command will set the value of `user` to an array with `1` and `2` as its values:

```shell:no-line-numbers
php artisan mail:send 1 2
```

This `*` character can be combined with an optional argument definition to allow zero or more instances of an argument:

```php
'mail:send {user?*}'
```

#### Option Arrays

When defining an option that expects multiple input values, each option value passed to the command should be prefixed with the option name:

```php
'mail:send {--id=*}'
```

Such a command may be invoked by passing multiple `--id` arguments:

```shell:no-line-numbers
php artisan mail:send --id=1 --id=2
```

### Input Descriptions

You may assign descriptions to input arguments and options by separating the argument name from the description using a colon. If you need a little extra room to define your command, feel free to spread the definition across multiple lines:

```php
/**
 * The name and signature of the console command.
 */
protected ?string $signature = 'mail:send
                        {user : The ID of the user}
                        {--queue : Whether the job should be queued}';
```

## Command I/O

### Retrieving Input

While your command is executing, you will likely need to access the values for the arguments and options accepted by your command. To do so, you may use the `argument` and `option` methods. If an argument or option does not exist, `null` will be returned:

```php
/**
 * Execute the console command.
 */
public function handle(): void
{
    $userId = $this->argument('user');
}
```

If you need to retrieve all of the arguments as an `array`, call the `arguments` method:

```php
$arguments = $this->arguments();
```

Options may be retrieved just as easily as arguments using the `option` method. To retrieve all of the options as an array, call the `options` method:

```php
// Retrieve a specific option...
$queueName = $this->option('queue');

// Retrieve all options as an array...
$options = $this->options();
```

### Prompting for Input

In addition to displaying output, you may also ask the user to provide input during the execution of your command. The `ask` method will prompt the user with the given question, accept their input, and then return the user's input back to your command:

```php
/**
 * Execute the console command.
 */
public function handle(): void
{
    $name = $this->ask('What is your name?');

    // ...
}
```

The `ask` method also accepts an optional second argument which specifies the default value that should be returned if no user input is provided:

```php
$name = $this->ask('What is your name?', 'Taylor');
```

The `secret` method is similar to `ask`, but the user's input will not be visible to them as they type in the console. This method is useful when asking for sensitive information such as passwords:

```php
$password = $this->secret('What is the password?');
```

#### Asking for Confirmation

If you need to ask the user for a simple "yes or no" confirmation, you may use the `confirm` method. By default, this method will return `false`. However, if the user enters `y` or `yes` in response to the prompt, the method will return `true`.

```php
if ($this->confirm('Do you wish to continue?')) {
    // ...
}
```

If necessary, you may specify that the confirmation prompt should return `true` by default by passing `true` as the second argument to the `confirm` method:

```php
if ($this->confirm('Do you wish to continue?', true)) {
    // ...
}
```

#### Auto-Completion

The `anticipate` method can be used to provide auto-completion for possible choices. The user can still provide any answer, regardless of the auto-completion hints:

```php
$name = $this->anticipate('What is your name?', ['Taylor', 'Dayle']);
```

Alternatively, you may pass a closure as the second argument to the `anticipate` method. The closure will be called each time the user types an input character. The closure should accept a string parameter containing the user's input so far, and return an array of options for auto-completion:

```php
$name = $this->anticipate('What is your address?', function (string $input) {
    // Return auto-completion options...
});
```

#### Multiple Choice Questions

If you need to give the user a predefined set of choices when asking a question, you may use the `choice` method. You may set the array index of the default value to be returned if no option is chosen by passing the index as the third argument to the method:

```php
$name = $this->choice(
    'What is your name?',
    ['Taylor', 'Dayle'],
    $defaultIndex
);
```

In addition, the `choice` method accepts optional fourth and fifth arguments for determining the maximum number of attempts to select a valid response and whether multiple selections are permitted:

```php
$name = $this->choice(
    'What is your name?',
    ['Taylor', 'Dayle'],
    $defaultIndex,
    $maxAttempts = null,
    $allowMultipleSelections = false
);
```

### Writing Output

To send output to the console, you may use the `line`, `info`, `comment`, `question`, `warn`, and `error` methods. Each of these methods will use appropriate ANSI colors for their purpose. For example, let's display some general information to the user. Typically, the `info` method will display in the console as green colored text:

```php
/**
 * Execute the console command.
 */
public function handle(): void
{
    // ...

    $this->info('The command was successful!');
}
```

To display an error message, use the `error` method. Error message text is typically displayed in red:

```php
$this->error('Something went wrong!');
```

You may use the `line` method to display plain, uncolored text:

```php
$this->line('Display this on the screen');
```

You may use the `newLine` method to display a blank line:

```php
// Write a single blank line...
$this->newLine();

// Write three blank lines...
$this->newLine(3);
```

#### Tables

The `table` method makes it easy to correctly format multiple rows / columns of data. All you need to do is provide the column names and the data for the table and Hypervel will
automatically calculate the appropriate width and height of the table for you:

```php
use App\Models\User;

$this->table(
    ['Name', 'Email'],
    User::all(['name', 'email'])->toArray()
);
```

#### Progress Bars

For long running tasks, it can be helpful to show a progress bar that informs users how complete the task is. Using the `withProgressBar` method, Hypervel will display a progress bar and advance its progress for each iteration over a given iterable value:

```php
use App\Models\User;

$users = $this->withProgressBar(User::all(), function (User $user) {
    $this->performTask($user);
});
```

Sometimes, you may need more manual control over how a progress bar is advanced. First, define the total number of steps the process will iterate through. Then, advance the progress bar after processing each item:

```php
$users = App\Models\User::all();

$bar = $this->output->createProgressBar(count($users));

$bar->start();

foreach ($users as $user) {
    $this->performTask($user);

    $bar->advance();
}

$bar->finish();
```

::: note
For more advanced options, check out the [Symfony Progress Bar component documentation](https://symfony.com/doc/current/components/console/helpers/progressbar).
:::

## Registering Commands

All of your console commands are registered within your application's `App\Console\Kernel` class, which is your application's "console kernel". Within the `commands` method of this class, you will see a call to the kernel's `load` method. The `load` method will scan the `app/Console/Commands` directory and automatically register each command it contains with Artisan. You are even free to make additional calls to the `load` method to scan other directories for Artisan commands:

```php
/**
 * Register the commands for the application.
 */
protected function commands(): void
{
    $this->load(__DIR__.'/Commands');
    $this->load(__DIR__.'/../Domain/Orders/Commands');

    // ...
}
```

If necessary, you may manually register commands by adding the command's class name to a `$commands` property within your `App\Console\Kernel` class. If this property is not already defined on your kernel, you should define it manually. When Artisan boots, all the commands listed in this property will be resolved by the [service container](/docs/container) and registered with Artisan:

```php
protected array $commands = [
    Commands\SendEmails::class
];
```

## Programmatically Executing Commands

Sometimes you may wish to execute an Artisan command outside of the CLI. For example, you may wish to execute an Artisan command from a route or controller. You may use the `call` method on the `Artisan` facade to accomplish this. The `call` method accepts either the command's signature name or class name as its first argument, and an array of command parameters as the second argument. The exit code will be returned:

```php
use Hypervel\Support\Facades\Artisan;

Route::post('/user/{user}/mail', function (string $user) {
    $exitCode = Artisan::call('mail:send', [
        'user' => $user, '--queue' => 'default'
    ]);

    // ...
});
```

Alternatively, you may pass the entire Artisan command to the `call` method as a string:

```php
Artisan::call('mail:send 1 --queue=default');
```

#### Passing Array Values

If your command defines an option that accepts an array, you may pass an array of values to that option:

```php
use Hypervel\Support\Facades\Artisan;

Route::post('/mail', function () {
    $exitCode = Artisan::call('mail:send', [
        '--id' => [5, 13]
    ]);
});
```

#### Passing Boolean Values

If you need to specify the value of an option that does not accept string values, such as the `--force` flag on the `migrate:refresh` command, you should pass `true` or `false` as the value of the option:

```php
$exitCode = Artisan::call('migrate:refresh', [
    '--force' => true,
]);
```

### Calling Commands From Other Commands

Sometimes you may wish to call other commands from an existing Artisan command. You may do so using the `call` method. This `call` method accepts the command name and an array of command arguments / options:

```php
/**
 * Execute the console command.
 */
public function handle(): void
{
    $this->call('mail:send', [
        'user' => 1, '--queue' => 'default'
    ]);

    // ...
}
```

If you would like to call another console command and suppress all of its output, you may use the `callSilently` method. The `callSilently` method has the same signature as the `call` method:

```php
$this->callSilently('mail:send', [
    'user' => 1, '--queue' => 'default'
]);
```

## Signal Handling

As you may know, operating systems allow signals to be sent to running processes. For example, the `SIGTERM` signal is how operating systems ask a program to terminate. If you wish to listen for signals in your Artisan console commands and execute code when they occur, you may use the `trap` method:

```php
/**
 * Execute the console command.
 */
public function handle(): void
{
    $this->trap(SIGTERM, fn () => $this->shouldKeepRunning = false);

    while ($this->shouldKeepRunning) {
        // ...
    }
}
```

To listen for multiple signals at once, you may provide an array of signals to the `trap` method:

```php
$this->trap([SIGTERM, SIGQUIT], function (int $signal) {
    $this->shouldKeepRunning = false;

    dump($signal); // SIGTERM / SIGQUIT
});
```

## Events

Artisan dispatches three events when running commands: `Hyperf\Command\Event\BeforeHandle`, `Hyperf\Command\Event\AfterHandle`, `Hyperf\Command\Event\AfterExecute` and `Hyperf\Command\Event\FailToHandle`.

The `BeforeHandle` event is dispatched immediately when Artisan starts running. Next, the `AfterExecute` event is dispatched immediately after a command runs. If an exception happened during the execution, the `FailToHandle` will be dispatched. Finally, the `AfterExecute` event is dispatched once a command finishes executing.