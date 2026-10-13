<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Support\CarbonImmutable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passport:keys:list')]
class KeysListCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passport:keys:list';

    /**
     * The console command description.
     */
    protected string $description = 'List database signing keys and their activation dates';

    /**
     * Execute the console command.
     */
    public function handle(SigningKeyRepository $keys): int
    {
        $now = CarbonImmutable::now();
        $stored = $keys->all();
        $active = $stored->first(static fn (SigningKey $key): bool => $key->getRawOriginal('private_key') !== null && $key->activates_at <= $now);

        $this->table(['Key ID', 'Created (UTC)', 'Activates (UTC)', 'Retires (UTC)', 'Status'], $stored->map(
            static fn (SigningKey $key): array => [
                ParsedKey::fromPublicKey($key->public_key)->getKeyId(),
                $key->created_at?->toDateTimeString(),
                $key->activates_at->toDateTimeString(),
                $key->retires_at?->toDateTimeString(),
                match (true) {
                    $key->is($active) => 'Signing',
                    $key->getRawOriginal('private_key') !== null && $key->activates_at > $now => 'Waiting',
                    default => 'Verify only',
                },
            ],
        )->all());

        return Command::SUCCESS;
    }
}
