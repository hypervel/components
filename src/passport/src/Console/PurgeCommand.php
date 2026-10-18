<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Passport\Passport;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passport:purge')]
class PurgeCommand extends Command
{
    protected const int BATCH_SIZE = 1000;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passport:purge
                            {--revoked : Only purge revoked tokens and authentication codes}
                            {--expired : Only purge expired tokens and authentication codes}
                            {--hours=168 : The number of hours to retain expired tokens}';

    /**
     * The console command description.
     */
    protected string $description = 'Purge revoked and / or expired tokens and authentication codes';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($hours === false) {
            throw new InvalidArgumentException('The --hours option must be a non-negative integer.');
        }

        $revoked = $this->option('revoked') || ! $this->option('expired');

        $expired = $this->option('expired') || ! $this->option('revoked')
            ? Date::now()->subHours($hours)
            : false;

        $now = Date::now();

        $constraint = fn (Builder $query): Builder => $query
            ->when($revoked, fn () => $query->orWhere('revoked', true))
            ->when($expired, fn () => $query->orWhere('expires_at', '<', $expired));
        $live = fn (Builder $query): Builder => $query
            ->where('revoked', false)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $now));

        $this->purge(Passport::token()->newQuery()
            ->where($constraint)
            ->whereDoesntHave('refreshToken', $live));

        // Used codes and refresh tokens remain replay evidence until their own expiry.
        $this->purge(Passport::authCode()->newQuery()->where($constraint)->where('expires_at', '<=', $now));

        // Family revocation also needs expired refresh rows while their access tokens are still usable.
        $this->purge(Passport::refreshToken()->newQuery()->where($constraint)->where('expires_at', '<=', $now)
            ->whereDoesntHave('accessToken', $live));
        $this->purge(Passport::refreshTokenFamily()->newQuery()->whereDoesntHave('refreshTokens'));

        if (Passport::$deviceCodeGrantEnabled) {
            $this->purge(Passport::deviceCode()->newQuery()->where($constraint));
        }

        if ($expired) {
            // Short-lived request references have no credential replay evidence to retain.
            $this->purge(Passport::pushedAuthorizationRequest()->newQuery()->where('expires_at', '<=', $now));
            $this->components->info('Purged expired pushed authorization requests.');
        }

        $this->components->info(sprintf('Purged %s.', implode(' and ', array_filter([
            $revoked ? 'revoked items' : null,
            $expired ? "items expired for more than {$expired->longAbsoluteDiffForHumans()}" : null,
        ]))));
    }

    /**
     * Delete matching records without locking the table during a full scan.
     */
    protected function purge(Builder $query): void
    {
        $query->select($query->getModel()->getQualifiedKeyName())
            ->chunkById(self::BATCH_SIZE, static function (Collection $records) use ($query): void {
                $ids = $records->map->getKey()->all();

                (clone $query)->whereKey($ids)->delete();
            });
    }
}
