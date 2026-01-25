<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Context\ApplicationContext;
use Hypervel\Support\Str;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use RuntimeException;

use function Hyperf\Config\config;

class Mix
{
    /**
     * Get the path to a versioned Mix file.
     *
     * @throws RuntimeException
     */
    public function __invoke(string $path, string $manifestDirectory = ''): HtmlString|string
    {
        static $manifests = [];

        if (! str_starts_with($path, '/')) {
            $path = "/{$path}";
        }

        if ($manifestDirectory && ! str_starts_with($manifestDirectory, '/')) {
            $manifestDirectory = "/{$manifestDirectory}";
        }

        if (is_file(public_path($manifestDirectory . '/hot'))) {
            $url = rtrim(file_get_contents(public_path($manifestDirectory . '/hot')));

            $customUrl = config('app.mix_hot_proxy_url');

            if (! empty($customUrl)) {
                return new HtmlString("{$customUrl}{$path}");
            }

            if (Str::startsWith($url, ['http://', 'https://'])) {
                return new HtmlString(Str::after($url, ':') . $path);
            }

            return new HtmlString("//localhost:8080{$path}");
        }

        $manifestPath = public_path($manifestDirectory . '/mix-manifest.json');

        if (! isset($manifests[$manifestPath])) {
            if (! is_file($manifestPath)) {
                throw new RuntimeException("Mix manifest not found at: {$manifestPath}");
            }

            $manifests[$manifestPath] = json_decode(file_get_contents($manifestPath), true);
        }

        $manifest = $manifests[$manifestPath];

        if (! isset($manifest[$path])) {
            $exception = new RuntimeException("Unable to locate Mix file: {$path}.");

            if (! config('app.debug')) {
                ApplicationContext::getContainer()
                    ->get(ExceptionHandlerContract::class)
                    ->report($exception);

                return $path;
            }
            throw $exception;
        }

        return new HtmlString(config('app.mix_url') . $manifestDirectory . $manifest[$path]);
    }
}
