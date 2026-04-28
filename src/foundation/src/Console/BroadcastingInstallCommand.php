<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Composer\InstalledVersions;
use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\confirm;
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
     * The framework packages to install.
     *
     * @var array<string, string>
     */
    protected array $frameworkPackages = [
        'react' => '@laravel/echo-react',
        'vue' => '@laravel/echo-vue',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->call('config:publish', ['name' => 'broadcasting']);

        // Install channel routes file...
        if (! file_exists($broadcastingRoutesPath = $this->hypervel->basePath('routes/channels.php')) || $this->option('force')) {
            $this->components->info("Published 'channels' route file.");

            copy(__DIR__ . '/stubs/broadcasting-routes.stub', $broadcastingRoutesPath);
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
            if (! file_exists($echoScriptPath = $this->hypervel->resourcePath('js/echo.js'))) {
                if (! is_dir($directory = $this->hypervel->resourcePath('js'))) {
                    mkdir($directory, 0755, true);
                }

                $stubPath = __DIR__ . '/stubs/echo-js-' . $this->driver . '.stub';

                if (! file_exists($stubPath)) {
                    $stubPath = __DIR__ . '/stubs/echo-js-reverb.stub';
                }

                copy($stubPath, $echoScriptPath);
            }

            // Only add the bootstrap import for the standard JS implementation...
            if (file_exists($bootstrapScriptPath = $this->hypervel->resourcePath('js/bootstrap.js'))) {
                $bootstrapScript = file_get_contents(
                    $bootstrapScriptPath
                );

                if (! str_contains($bootstrapScript, './echo')) {
                    file_put_contents(
                        $bootstrapScriptPath,
                        trim($bootstrapScript . PHP_EOL . file_get_contents(__DIR__ . '/stubs/echo-bootstrap-js.stub')) . PHP_EOL,
                    );
                }
            } elseif (file_exists($appScriptPath = $this->hypervel->resourcePath('js/app.js'))) {
                // If no bootstrap.js, try app.js...
                $appScript = file_get_contents(
                    $appScriptPath
                );

                if (! str_contains($appScript, './echo')) {
                    file_put_contents(
                        $appScriptPath,
                        trim($appScript . PHP_EOL . file_get_contents(__DIR__ . '/stubs/echo-bootstrap-js.stub')) . PHP_EOL,
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

        $content = file_get_contents($appBootstrapPath);

        if (str_contains($content, '// channels: ')) {
            (new Filesystem)->replaceInFile(
                '// channels: ',
                'channels: ',
                $appBootstrapPath,
            );
        } elseif (str_contains($content, 'channels: ')) {
            return;
        } elseif (str_contains($content, "commands: __DIR__ . '/../routes/console.php',")) {
            (new Filesystem)->replaceInFile(
                "commands: __DIR__ . '/../routes/console.php',",
                "commands: __DIR__ . '/../routes/console.php'," . PHP_EOL . "        channels: __DIR__ . '/../routes/channels.php',",
                $appBootstrapPath,
            );
        } elseif (str_contains($content, '->withRouting(')) {
            (new Filesystem)->replaceInFile(
                '->withRouting(',
                '->withRouting(' . PHP_EOL . "        channels: __DIR__ . '/../routes/channels.php',",
                $appBootstrapPath,
            );
        } else {
            $this->components->error('Unable to register broadcast routes. Please register them manually in [' . $appBootstrapPath . '].');
        }
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
            default => null,
        };
    }

    /**
     * Install the driver packages.
     */
    protected function installDriverPackages(): void
    {
        $package = match ($this->driver) {
            'pusher' => 'pusher/pusher-php-server',
            'ably' => 'ably/ably-php',
            default => null,
        };

        if (! $package || InstalledVersions::isInstalled($package)) {
            return;
        }

        $this->requireComposerPackages((string) $this->option('composer'), [$package]);
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
     * Inject Echo configuration into the application's main file.
     */
    protected function injectFrameworkSpecificConfiguration(): void
    {
        if ($this->appUsesVue()) {
            $importPath = $this->frameworkPackages['vue'];

            $filePaths = [
                $this->hypervel->resourcePath('js/app.ts'),
                $this->hypervel->resourcePath('js/app.js'),
            ];
        } else {
            $importPath = $this->frameworkPackages['react'];

            $filePaths = [
                $this->hypervel->resourcePath('js/app.tsx'),
                $this->hypervel->resourcePath('js/app.jsx'),
            ];
        }

        $filePath = Arr::first($filePaths, fn (string $path): bool => file_exists($path));

        if (! $filePath) {
            $this->components->warn("Could not find file [{$filePaths[0]}]. Skipping automatic Echo configuration.");

            return;
        }

        $contents = file_get_contents($filePath);

        $echoCode = <<<JS
        import { configureEcho } from '{$importPath}';

        configureEcho({
            broadcaster: '{$this->driver}',
        });
        JS;

        preg_match_all('/^import .+;$/m', $contents, $matches);

        if (empty($matches[0])) {
            // Add the Echo configuration to the top of the file if no import statements are found...
            $newContents = $echoCode . PHP_EOL . $contents;

            file_put_contents($filePath, $newContents);
        } else {
            // Add Echo configuration after the last import...
            $lastImport = array_last($matches[0]);

            $positionOfLastImport = strrpos($contents, $lastImport);

            if ($positionOfLastImport !== false) {
                $insertPosition = $positionOfLastImport + strlen($lastImport);
                $newContents = substr($contents, 0, $insertPosition) . PHP_EOL . $echoCode . substr($contents, $insertPosition);

                file_put_contents($filePath, $newContents);
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
        ]);

        Process::run([
            php_binary(),
            artisan_binary(),
            'reverb:install',
        ]);

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

        $this->components->info('Installing and building Node dependencies.');

        if (file_exists($this->hypervel->basePath('pnpm-lock.yaml'))) {
            $commands = [
                'pnpm add --save-dev laravel-echo pusher-js',
                'pnpm run build',
            ];
        } elseif (file_exists($this->hypervel->basePath('yarn.lock'))) {
            $commands = [
                'yarn add --dev laravel-echo pusher-js',
                'yarn run build',
            ];
        } elseif (file_exists($this->hypervel->basePath('bun.lock')) || file_exists($this->hypervel->basePath('bun.lockb'))) {
            $commands = [
                'bun add --dev laravel-echo pusher-js',
                'bun run build',
            ];
        } else {
            $commands = [
                'npm install --save-dev laravel-echo pusher-js',
                'npm run build',
            ];
        }

        if ($this->appUsesVue()) {
            $commands[0] .= ' ' . $this->frameworkPackages['vue'];
        } elseif ($this->appUsesReact()) {
            $commands[0] .= ' ' . $this->frameworkPackages['react'];
        }

        $command = Process::command(implode(' && ', $commands))
            ->path($this->hypervel->basePath());

        if (! windows_os()) {
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
        if ($this->option('reverb')) {
            return 'reverb';
        }

        if ($this->option('pusher')) {
            return 'pusher';
        }

        if ($this->option('ably')) {
            return 'ably';
        }

        return select('Which broadcasting driver would you like to use?', [
            'reverb' => 'Hypervel Reverb',
            'pusher' => 'Pusher',
            'ably' => 'Ably',
        ]);
    }

    /**
     * Detect if the user is using a supported framework (React or Vue).
     */
    protected function isUsingSupportedFramework(): bool
    {
        return $this->appUsesReact() || $this->appUsesVue();
    }

    /**
     * Detect if the user is using React.
     */
    protected function appUsesReact(): bool
    {
        return $this->packageDependenciesInclude('react');
    }

    /**
     * Detect if the user is using Vue.
     */
    protected function appUsesVue(): bool
    {
        return $this->packageDependenciesInclude('vue');
    }

    /**
     * Detect if the package is installed.
     */
    protected function packageDependenciesInclude(string $package): bool
    {
        $packageJsonPath = $this->hypervel->basePath('package.json');

        if (! file_exists($packageJsonPath)) {
            return false;
        }

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);

        return isset($packageJson['dependencies'][$package])
            || isset($packageJson['devDependencies'][$package]);
    }
}
