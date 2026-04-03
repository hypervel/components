<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Bootstrap;

use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Workbench\Workbench;
use Override;
use Symfony\Component\Finder\Finder;

use function Hypervel\Testbench\workbench_path;

/**
 * @internal
 */
class LoadConfigurationWithWorkbench extends LoadConfiguration
{
    /**
     * Determine if workbench config files should be loaded.
     */
    protected readonly bool $usesWorkbenchConfigFile;

    public function __construct()
    {
        $this->usesWorkbenchConfigFile = Workbench::configuration()->getWorkbenchDiscoversAttributes()['config'] === true
            && is_dir(workbench_path('config'));
    }

    #[Override]
    public function bootstrap(Application $app): void
    {
        parent::bootstrap($app);

        $userModel = Workbench::applicationUserModel();

        if (is_string($userModel) && is_a($userModel, AuthenticatableContract::class, true)) { /* @phpstan-ignore function.alreadyNarrowedType */
            $app->make('config')->set('auth.providers.users.model', $userModel);
        }
    }

    #[Override]
    protected function resolveConfigurationFile(string $path, string $key): string
    {
        $config = workbench_path('config', "{$key}.php");

        return $this->usesWorkbenchConfigFile && is_file($config) ? $config : $path;
    }

    /**
     * @param Collection<string, string> $configurations
     * @return Collection<string, string>
     */
    #[Override]
    protected function extendsLoadedConfiguration(Collection $configurations): Collection
    {
        if (! $this->usesWorkbenchConfigFile) {
            return $configurations;
        }

        (new LazyCollection(function () {
            $path = workbench_path('config');

            foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
                $directory = $this->getNestedDirectory($file, $path);

                yield $directory . basename($file->getRealPath(), '.php') => $file->getRealPath();
            }
        }))->reject(static fn (string $path, string $key): bool => $configurations->has($key))
            ->each(static function (string $path, string $key) use ($configurations): void {
                $configurations->put($key, $path);
            });

        return $configurations;
    }
}
