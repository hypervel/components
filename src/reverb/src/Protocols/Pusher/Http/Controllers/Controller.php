<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Http\VerifiedRequestContext;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /**
     * Verify that the incoming request is valid.
     *
     * Returns a per-request context DTO instead of storing state on instance
     * properties — controllers are shared across coroutines in Hypervel.
     */
    protected function verify(Request $request, string $appId): VerifiedRequestContext
    {
        $body = $request->getContent();
        $query = $request->query->all();

        $application = $this->resolveApplication($appId);
        $channels = app(ChannelManager::class)->for($application);

        $this->verifySignature($request, $application, $body, $query);

        return new VerifiedRequestContext($application, $channels, $body, $query);
    }

    /**
     * Resolve the application instance for the given ID.
     *
     * @throws HttpException
     */
    protected function resolveApplication(string $appId): Application
    {
        if (! $appId) {
            throw new HttpException(400, 'Application ID not provided.');
        }

        try {
            return app(ApplicationProvider::class)->findById($appId);
        } catch (InvalidApplication) {
            throw new HttpException(404, 'No matching application for ID [' . $appId . '].');
        }
    }

    /**
     * Verify the Pusher authentication signature.
     *
     * @throws HttpException
     */
    protected function verifySignature(Request $request, Application $application, string $body, array $query): void
    {
        $params = Arr::except($query, [
            'auth_signature', 'body_md5', 'appId', 'appKey', 'channelName',
        ]);

        if ($body !== '') {
            $params['body_md5'] = md5($body);
        }

        ksort($params);

        $path = $request->getPathInfo();

        if ($prefix = config('reverb.servers.reverb.path')) {
            $path = '/' . ltrim(Str::after($path, rtrim($prefix, '/')), '/');
        }

        $signature = implode("\n", [
            $request->getMethod(),
            $path,
            static::formatQueryParametersForVerification($params),
        ]);

        $signature = hash_hmac('sha256', $signature, $application->secret());
        $authSignature = $query['auth_signature'] ?? '';

        if ($signature !== $authSignature) {
            throw new HttpException(401, 'Authentication signature invalid.');
        }
    }

    /**
     * Format the given parameters into the correct format for signature verification.
     */
    protected static function formatQueryParametersForVerification(array $params): string
    {
        return collect($params)->map(function ($value, $key) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }

            return "{$key}={$value}";
        })->implode('&');
    }
}
