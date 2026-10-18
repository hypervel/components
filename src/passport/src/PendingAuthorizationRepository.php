<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;

class PendingAuthorizationRepository
{
    protected const string SESSION_KEY = 'passport.authorizations';

    /**
     * Create the pending authorization repository.
     */
    public function __construct(protected Repository $config)
    {
    }

    /**
     * Store an authorization without replacing other pending browser interactions.
     *
     * @param array<string, mixed> $authorization
     */
    public function store(Request $request, array $authorization): string
    {
        $pending = $this->pending($request);
        $limit = $this->config->integer('passport.authorization.max_pending');

        while (count($pending) >= $limit) {
            array_shift($pending);
        }

        $id = Str::random(40);
        $pending[$id] = [
            ...$authorization,
            // Account switching replaces the session, but must not extend the preserved interaction.
            'expires_at' => min(
                $authorization['expires_at'] ?? PHP_INT_MAX,
                Date::now()->getTimestamp() + $this->config->integer('passport.authorization.lifetime'),
            ),
        ];
        $request->session()->put(self::SESSION_KEY, $pending);

        return $id;
    }

    /**
     * Get one unexpired browser interaction.
     *
     * @return array<string, mixed>
     */
    public function get(Request $request, string $id): array
    {
        return $this->pending($request)[$id] ?? throw InvalidAuthTokenException::different();
    }

    /**
     * Update trusted interaction state without extending its lifetime.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(Request $request, string $id, array $attributes): void
    {
        $pending = $this->pending($request);
        $authorization = $pending[$id] ?? throw InvalidAuthTokenException::different();
        $pending[$id] = [...$authorization, ...$attributes, 'expires_at' => $authorization['expires_at']];
        $request->session()->put(self::SESSION_KEY, $pending);
    }

    /**
     * Bind guest interactions to the account that explicitly signed in.
     */
    public function confirmLogin(Request $request, string $guard, string $userId, ?string $provider): void
    {
        $pending = $this->pending($request);
        $changed = false;

        foreach ($pending as $id => $authorization) {
            if ($authorization['stage'] === 'login' && $authorization['guard'] === $guard
                && ! isset($authorization['user_id'])) {
                $pending[$id] = [...$authorization, 'login_confirmed' => true, 'user_id' => $userId, 'provider' => $provider];
                $changed = true;
            }
        }

        if ($changed) {
            $request->session()->put(self::SESSION_KEY, $pending);
        }
    }

    /**
     * Consume one decision while the browser route holds its session lock.
     *
     * @return array<string, mixed>
     */
    public function pull(Request $request, string $id): array
    {
        $pending = $this->pending($request);
        $authorization = $pending[$id] ?? throw InvalidAuthTokenException::different();
        unset($pending[$id]);
        $request->session()->put(self::SESSION_KEY, $pending);

        return $authorization;
    }

    /**
     * Remove expired authorizations from the browser session.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function pending(Request $request): array
    {
        $pending = $request->session()->get(self::SESSION_KEY, []);
        $now = Date::now()->getTimestamp();
        $active = array_filter($pending, static fn (array $authorization): bool => $authorization['expires_at'] > $now);

        if (count($active) !== count($pending)) {
            $request->session()->put(self::SESSION_KEY, $active);
        }

        return $active;
    }
}
