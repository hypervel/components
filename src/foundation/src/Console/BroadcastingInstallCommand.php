<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Composer\InstalledVersions;
use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\note;
use function Hypervel\Prompts\password;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\text;
use function Hypervel\Support\artisan_binary;
use function Hypervel\Support\php_binary;

#[AsCommand(name: 'install:broadcasting')]
class BroadcastingInstallCommand extends Command
{
    use InteractsWithComposerPackages;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'install:broadcasting
                    {--composer=global : Absolute path to the Composer binary which should be used to install packages}
                    {--force : Overwrite any existing broadcasting routes file}
                    {--without-reverb : Do not prompt to install Hypervel Reverb}
                    {--reverb : Install Hypervel Reverb as the default broadcaster}
                    {--pusher : Install Pusher as the default broadcaster}
                    {--ably : Install Ably as the default broadcaster}
                    {--mercure : Install Mercure as the default broadcaster}
                    {--pretend : Run dependency installation commands in dry-run mode}
                    {--without-node : Do not prompt to install Node dependencies}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a broadcasting channel routes file';

    /**
     * The broadcasting driver to use.
     */
    protected ?string $driver = null;

    /**
     * The Composer packages required by each broadcasting driver.
     *
     * @var array<string, array<string, string>>
     */
    protected array $driverPackages = [
        'pusher' => ['pusher/pusher-php-server' => '*'],
        'ably' => ['ably/ably-php' => '*'],
        'mercure' => ['symfony/mercure' => '^0.8', 'symfony/http-client' => '^8.1', 'web-token/jwt-library' => '^4.2.3'],
    ];

    /**
     * The React Echo package to install.
     */
    protected string $reactEchoPackage = '@laravel/echo-react';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if ($this->call('config:publish', ['name' => 'broadcasting']) !== self::SUCCESS) {
            throw new RuntimeException('Unable to publish the broadcasting configuration file.');
        }

        $files = $this->hypervel->make(Filesystem::class);
        $broadcastingRoutesPath = $this->hypervel->basePath('routes/channels.php');

        // Install channel routes file...
        if (! $files->exists($broadcastingRoutesPath) || $this->option('force')) {
            $files->ensureDirectoryExists(dirname($broadcastingRoutesPath));

            $mode = null;

            if ($files->exists($broadcastingRoutesPath)) {
                $permissions = $files->chmod($broadcastingRoutesPath);

                if ($permissions === false) {
                    throw new RuntimeException("Unable to determine permissions for [{$broadcastingRoutesPath}].");
                }

                $mode = octdec($permissions);
            }

            $files->replace(
                $broadcastingRoutesPath,
                $files->get(__DIR__ . '/stubs/broadcasting-routes.stub'),
                $mode
            );

            $this->components->info("Published 'channels' route file.");
        }

        $this->uncommentChannelsRoutesFile();

        $this->driver = $this->resolveDriver();

        Env::writeVariable('BROADCAST_CONNECTION', $this->driver, $this->hypervel->basePath('.env'), true);

        $this->collectDriverConfig();
        $this->installDriverPackages();

        if ($this->isUsingSupportedFramework()) {
            // If this is a supported framework, we will use the framework-specific Echo helpers...
            $this->injectFrameworkSpecificConfiguration();
        } else {
            // Standard JavaScript implementation...
            if (! $files->exists($echoScriptPath = $this->hypervel->resourcePath('js/echo.js'))) {
                $files->ensureDirectoryExists($this->hypervel->resourcePath('js'));

                $stubPath = __DIR__ . '/stubs/echo-js-' . $this->driver . '.stub';

                if (! $files->exists($stubPath)) {
                    $stubPath = __DIR__ . '/stubs/echo-js-reverb.stub';
                }

                $files->replace($echoScriptPath, $files->get($stubPath));
            }

            // Only add the bootstrap import for the standard JS implementation...
            if ($files->exists($bootstrapScriptPath = $this->hypervel->resourcePath('js/bootstrap.js'))) {
                $bootstrapScript = $files->get($bootstrapScriptPath);

                if (! str_contains($bootstrapScript, './echo')) {
                    $permissions = $files->chmod($bootstrapScriptPath);

                    if ($permissions === false) {
                        throw new RuntimeException("Unable to determine permissions for [{$bootstrapScriptPath}].");
                    }

                    $files->replace(
                        $bootstrapScriptPath,
                        trim($bootstrapScript . PHP_EOL . $files->get(__DIR__ . '/stubs/echo-bootstrap-js.stub')) . PHP_EOL,
                        octdec($permissions)
                    );
                }
            } elseif ($files->exists($appScriptPath = $this->hypervel->resourcePath('js/app.js'))) {
                // If no bootstrap.js, try app.js...
                $appScript = $files->get($appScriptPath);

                if (! str_contains($appScript, './echo')) {
                    $permissions = $files->chmod($appScriptPath);

                    if ($permissions === false) {
                        throw new RuntimeException("Unable to determine permissions for [{$appScriptPath}].");
                    }

                    $files->replace(
                        $appScriptPath,
                        trim($appScript . PHP_EOL . $files->get(__DIR__ . '/stubs/echo-bootstrap-js.stub')) . PHP_EOL,
                        octdec($permissions)
                    );
                }
            }
        }

        $this->installReverb();

        $this->installNodeDependencies();
    }

    /**
     * Uncomment the "channels" routes file in the application bootstrap file.
     */
    protected function uncommentChannelsRoutesFile(): void
    {
        $appBootstrapPath = $this->hypervel->bootstrapPath('app.php');
        $files = $this->hypervel->make(Filesystem::class);

        $content = $files->get($appBootstrapPath);

        if (str_contains($content, '// channels: ')) {
            $content = str_replace(
                '// channels: ',
                'channels: ',
                $content,
            );
        } elseif (str_contains($content, 'channels: ')) {
            return;
        } elseif (str_contains($content, "commands: __DIR__ . '/../routes/console.php',")) {
            $content = str_replace(
                "commands: __DIR__ . '/../routes/console.php',",
                "commands: __DIR__ . '/../routes/console.php'," . PHP_EOL . "        channels: __DIR__ . '/../routes/channels.php',",
                $content,
            );
        } elseif (str_contains($content, '->withRouting(')) {
            $content = str_replace(
                '->withRouting(',
                '->withRouting(' . PHP_EOL . "        channels: __DIR__ . '/../routes/channels.php',",
                $content,
            );
        } else {
            $this->components->error('Unable to register broadcast routes. Please register them manually in [' . $appBootstrapPath . '].');

            return;
        }

        $permissions = $files->chmod($appBootstrapPath);

        if ($permissions === false) {
            throw new RuntimeException("Unable to determine permissions for [{$appBootstrapPath}].");
        }

        $files->replace($appBootstrapPath, $content, octdec($permissions));
    }

    /**
     * Collect the driver configuration.
     */
    protected function collectDriverConfig(): void
    {
        $envPath = $this->hypervel->basePath('.env');

        if (! file_exists($envPath)) {
            return;
        }

        match ($this->driver) {
            'pusher' => $this->collectPusherConfig(),
            'ably' => $this->collectAblyConfig(),
            'mercure' => $this->collectMercureConfig(),
            default => null,
        };
    }

    /**
     * Install the driver packages.
     */
    protected function installDriverPackages(): void
    {
        $packages = array_filter(
            $this->driverPackages[$this->driver] ?? [],
            // Composer enforces explicit constraints, since an installed version may be incompatible.
            fn (string $constraint, string $package): bool => $constraint !== '*' || ! InstalledVersions::isInstalled($package),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($packages === []) {
            return;
        }

        $this->requireComposerPackages(
            (string) $this->option('composer'),
            array_map(
                fn (string $package, string $constraint): string => $constraint === '*' ? $package : $package . ':' . $constraint,
                array_keys($packages),
                $packages,
            ),
            (bool) $this->option('pretend')
        );
    }

    /**
     * Collect the Pusher configuration.
     */
    protected function collectPusherConfig(): void
    {
        $appId = text('Pusher App ID', 'Enter your Pusher app ID');
        $key = password('Pusher App Key', 'Enter your Pusher app key');
        $secret = password('Pusher App Secret', 'Enter your Pusher app secret');

        $cluster = select('Pusher App Cluster', [
            'mt1',
            'us2',
            'us3',
            'eu',
            'ap1',
            'ap2',
            'ap3',
            'ap4',
            'sa1',
        ]);

        Env::writeVariables([
            'PUSHER_APP_ID' => $appId,
            'PUSHER_APP_KEY' => $key,
            'PUSHER_APP_SECRET' => $secret,
            'PUSHER_APP_CLUSTER' => $cluster,
            'PUSHER_PORT' => 443,
            'PUSHER_SCHEME' => 'https',
            'VITE_PUSHER_APP_KEY' => '${PUSHER_APP_KEY}',
            'VITE_PUSHER_APP_CLUSTER' => '${PUSHER_APP_CLUSTER}',
            'VITE_PUSHER_HOST' => '${PUSHER_HOST}',
            'VITE_PUSHER_PORT' => '${PUSHER_PORT}',
            'VITE_PUSHER_SCHEME' => '${PUSHER_SCHEME}',
        ], $this->hypervel->basePath('.env'));
    }

    /**
     * Collect the Ably configuration.
     */
    protected function collectAblyConfig(): void
    {
        $this->components->warn('Make sure to enable "Pusher protocol support" in your Ably app settings.');

        $key = password('Ably Key', 'Enter your Ably key');

        $publicKey = explode(':', $key)[0];

        Env::writeVariables([
            'ABLY_KEY' => $key,
            'ABLY_PUBLIC_KEY' => $publicKey,
            'VITE_ABLY_PUBLIC_KEY' => '${ABLY_PUBLIC_KEY}',
        ], $this->hypervel->basePath('.env'));
    }

    /**
     * Collect the Mercure configuration.
     */
    protected function collectMercureConfig(): void
    {
        // REMOVED: FrankenPHP's in-process hub choice; Hypervel publishes to standalone hubs.
        $url = text(
            'Mercure Hub URL',
            'https://example.com/.well-known/mercure',
            required: true,
            hint: 'The URL your application publishes updates to.',
        );

        $publicUrl = text(
            'Mercure Hub Public URL',
            default: $url,
            required: true,
            hint: 'The URL browsers subscribe to updates from.',
        );

        $variables = [
            'MERCURE_URL' => $url,
            'MERCURE_PUBLIC_URL' => $publicUrl,
            'VITE_MERCURE_HUB_URL' => '${MERCURE_PUBLIC_URL}',
        ];

        if (strtolower((string) parse_url($publicUrl, PHP_URL_SCHEME)) === 'http') {
            $variables['MERCURE_COOKIE_NAME'] = 'mercure_access_token';

            $this->components->warn('Set "cookie_name mercure_access_token" on your Mercure hub.');
        }

        $variables['MERCURE_JWT_SECRET'] = password(
            'Mercure JWT Secret',
            'Leave empty to generate a random secret',
            validate: fn (string $value): ?string => $value === '' || strlen($value) >= 32
                ? null
                : 'The secret must be at least 32 bytes long.',
            hint: 'Signs the tokens that let your app publish and browsers subscribe.',
        ) ?: bin2hex(random_bytes(32));

        if (confirm('Would you like to enable end-to-end encrypted channels?', default: false)) {
            $variables['MERCURE_ENCRYPTION_KEY'] = 'base64:' . base64_encode(random_bytes(32));
        }

        Env::writeVariables($variables, $this->hypervel->basePath('.env'));
    }

    /**
     * Inject Echo configuration into the application's main file.
     */
    protected function injectFrameworkSpecificConfiguration(): void
    {
        $files = $this->hypervel->make(Filesystem::class);
        $filePaths = [
            $this->hypervel->resourcePath('js/app.tsx'),
            $this->hypervel->resourcePath('js/app.jsx'),
        ];

        $filePath = Arr::first($filePaths, fn (string $path): bool => $files->exists($path));

        if (! $filePath) {
            $this->components->warn("Could not find file [{$filePaths[0]}]. Skipping automatic Echo configuration.");

            return;
        }

        $contents = $files->get($filePath);
        $permissions = $files->chmod($filePath);

        if ($permissions === false) {
            throw new RuntimeException("Unable to determine permissions for [{$filePath}].");
        }

        $echoCode = <<<JS
        import { configureEcho } from '{$this->reactEchoPackage}';

        configureEcho({
            broadcaster: '{$this->driver}',
        });
        JS;

        preg_match_all('/^import .+;$/m', $contents, $matches);

        if (empty($matches[0])) {
            // Add the Echo configuration to the top of the file if no import statements are found...
            $newContents = $echoCode . PHP_EOL . $contents;

            $files->replace($filePath, $newContents, octdec($permissions));
        } else {
            // Add Echo configuration after the last import...
            $lastImport = array_last($matches[0]);

            $positionOfLastImport = strrpos($contents, $lastImport);

            if ($positionOfLastImport !== false) {
                $insertPosition = $positionOfLastImport + strlen($lastImport);
                $newContents = substr($contents, 0, $insertPosition) . PHP_EOL . $echoCode . substr($contents, $insertPosition);

                $files->replace($filePath, $newContents, octdec($permissions));
            }
        }

        $this->components->info('Echo configuration added to [' . basename($filePath) . '].');
    }

    /**
     * Install Hypervel Reverb into the application if desired.
     */
    protected function installReverb(): void
    {
        if ($this->driver !== 'reverb' || $this->option('without-reverb') || InstalledVersions::isInstalled('hypervel/reverb')) {
            return;
        }

        if (! confirm('Would you like to install Hypervel Reverb?', default: true)) {
            return;
        }

        $this->requireComposerPackages((string) $this->option('composer'), [
            'hypervel/reverb:^0.4',
        ], (bool) $this->option('pretend'));

        if ($this->option('pretend')) {
            return;
        }

        Process::run([
            php_binary(),
            artisan_binary(),
            'reverb:install',
        ])->throw();

        $this->components->info('Reverb installed successfully.');
    }

    /**
     * Install and build Node dependencies.
     */
    protected function installNodeDependencies(): void
    {
        if ($this->option('without-node') || ! confirm('Would you like to install and build the Node dependencies required for broadcasting?', default: true)) {
            return;
        }

        if (file_exists($this->hypervel->basePath('pnpm-lock.yaml'))) {
            $commands = [
                'pnpm add --save-dev laravel-echo pusher-js --ignore-scripts',
                'pnpm run build',
            ];
        } elseif (file_exists($this->hypervel->basePath('yarn.lock'))) {
            $yarnMajorVersion = (int) Process::path($this->hypervel->basePath())->run('yarn --version')->throw()->output();

            // Yarn 2+ rejects --ignore-scripts on add. Yarn 2 also lacks --mode=skip-build, and its
            // enableScripts setting still runs scripts the application has explicitly enabled.
            $commands = [
                match ($yarnMajorVersion) {
                    1 => 'yarn add --dev laravel-echo pusher-js --ignore-scripts',
                    2 => 'YARN_ENABLE_SCRIPTS=false yarn add --dev laravel-echo pusher-js',
                    default => 'yarn add --dev laravel-echo pusher-js --mode=skip-build',
                },
                'yarn run build',
            ];
        } elseif (file_exists($this->hypervel->basePath('bun.lock')) || file_exists($this->hypervel->basePath('bun.lockb'))) {
            $commands = [
                'bun add --dev laravel-echo pusher-js',
                'bun run build',
            ];
        } else {
            $commands = [
                'npm install --save-dev laravel-echo pusher-js --ignore-scripts',
                'npm run build',
            ];
        }

        if ($this->appUsesReact()) {
            $commands[0] .= ' ' . $this->reactEchoPackage;
        }

        if ($this->option('pretend')) {
            note(implode(' && ', $commands));

            return;
        }

        $this->components->info('Installing and building Node dependencies.');

        $command = Process::command(implode(' && ', $commands))
            ->path($this->hypervel->basePath());

        // Gate TTY on real support; the installer can run in containers or CI without a usable /dev/tty.
        if (! windows_os() && $command->supportsTty()) {
            $command->tty(true);
        }

        if ($command->run()->failed()) {
            $this->components->warn("Node dependency installation failed. Please run the following commands manually: \n\n" . implode(' && ', $commands));
        } else {
            $this->components->info('Node dependencies installed successfully.');
        }
    }

    /**
     * Resolve the driver to use based on the user's choice.
     */
    protected function resolveDriver(): string
    {
        return match (true) {
            $this->option('reverb') => 'reverb',
            $this->option('pusher') => 'pusher',
            $this->option('ably') => 'ably',
            $this->option('mercure') => 'mercure',
            default => select('Which broadcasting driver would you like to use?', [
                'reverb' => 'Hypervel Reverb',
                'pusher' => 'Pusher',
                'ably' => 'Ably',
                'mercure' => 'Mercure',
            ]),
        };
    }

    /**
     * Detect if the user is using a supported framework.
     */
    protected function isUsingSupportedFramework(): bool
    {
        return $this->appUsesReact();
    }

    /**
     * Detect if the user is using React.
     */
    protected function appUsesReact(): bool
    {
        return $this->packageDependenciesInclude('react');
    }

    /**
     * Detect if the package is installed.
     */
    protected function packageDependenciesInclude(string $package): bool
    {
        $packageJsonPath = $this->hypervel->basePath('package.json');
        $files = $this->hypervel->make(Filesystem::class);

        if (! $files->exists($packageJsonPath)) {
            return false;
        }

        try {
            $packageJson = json_decode($files->get($packageJsonPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to parse package file [{$packageJsonPath}].", previous: $exception);
        }

        if (! is_array($packageJson)
            || ! is_array($dependencies = $packageJson['dependencies'] ?? [])
            || ! is_array($devDependencies = $packageJson['devDependencies'] ?? [])) {
            throw new RuntimeException("Package file [{$packageJsonPath}] does not contain valid dependency maps.");
        }

        return isset($dependencies[$package]) || isset($devDependencies[$package]);
    }
}
