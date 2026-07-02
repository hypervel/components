<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Passkeys\Actions\PruneOrphanedPasskeys;
use Hypervel\Passkeys\Passkey;
use Hypervel\Support\Collection;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;
use ReflectionMethod;

class PruneOrphanedPasskeysTest extends TestCase
{
    public function testItRemovesOrphansCreatedByMassDeletingAnOwner(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $this->createPasskeyForUser($user, 'credential-mass-delete');

        User::query()->whereKey($user->getKey())->delete();

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
        ]);

        $this->assertSame([
            $user->getMorphClass() => 1,
        ], (new PruneOrphanedPasskeys)());

        $this->assertSame(0, Passkey::query()->count());
    }

    public function testDryRunCommandDoesNotDeleteOrphans(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $this->createPasskeyForUser($user, 'credential-dry-run');

        User::query()->whereKey($user->getKey())->delete();

        $this->artisan('passkeys:prune-orphans', ['--dry-run' => true])
            ->expectsOutputToContain($user->getMorphClass() . ': 1')
            ->expectsOutputToContain('Found 1 orphaned passkeys.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
        ]);
    }

    public function testItSkipsUnresolvedMorphAliasesWithoutDeletingPasskeys(): void
    {
        Relation::morphMap([
            'passkey-users' => User::class,
        ], false);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $this->createPasskeyForUser($user, 'credential-mapped-user');

        Relation::morphMap([], false);

        $warnings = [];
        $result = (new PruneOrphanedPasskeys)(
            warn: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            }
        );

        $this->assertSame([], $result);
        $this->assertSame([
            'Skipping passkeys for unresolved morph alias [passkey-users]. Register the morph map before pruning.',
        ], $warnings);
        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
            'user_type' => 'passkey-users',
        ]);
    }

    public function testItDeletesPasskeysForMissingOwnerClasses(): void
    {
        $missingOwnerClass = 'Hypervel\Tests\Passkeys\Fixtures\MissingPasskeyOwner';

        $passkey = new Passkey;
        $passkey->forceFill([
            'user_type' => $missingOwnerClass,
            'user_id' => 1,
            'name' => 'Laptop',
            'credential_id' => 'credential-missing-owner-class',
            'credential' => ['id' => 'credential-missing-owner-class'],
        ])->save();

        $this->assertSame([
            $missingOwnerClass => 1,
        ], (new PruneOrphanedPasskeys)());

        $this->assertDatabaseMissing('passkeys', [
            'id' => $passkey->getKey(),
        ]);
    }

    public function testCommandWarnsAndSkipsUnresolvedMorphAliases(): void
    {
        Relation::morphMap([
            'passkey-users' => User::class,
        ], false);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $this->createPasskeyForUser($user, 'credential-mapped-command-user');

        Relation::morphMap([], false);

        $this->artisan('passkeys:prune-orphans')
            ->expectsOutputToContain('Skipping passkeys for unresolved morph alias [passkey-users]. Register the morph map before pruning.')
            ->expectsOutputToContain('Pruned 0 orphaned passkeys.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
        ]);
    }

    public function testItIgnoresChunksWithNoScalarOwnerIds(): void
    {
        $passkey = new Passkey;
        $passkey->forceFill([
            'id' => 1,
            'user_id' => ['not-scalar'],
        ]);

        $action = new PruneOrphanedPasskeys;
        $method = new ReflectionMethod($action, 'orphanedPasskeyIds');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($action, new Collection([$passkey]), User::class));
    }

    public function testItIgnoresOwnerGlobalScopesWhenCheckingForOrphans(): void
    {
        $user = ScopedPasskeyUser::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $this->createPasskeyForUser($user, 'credential-scoped-owner');

        $this->assertSame([], (new PruneOrphanedPasskeys)());

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
            'user_type' => $user->getMorphClass(),
            'user_id' => (string) $user->getKey(),
        ]);
    }

    /**
     * Create a passkey for the given user.
     */
    private function createPasskeyForUser(User $user, string $credentialId): Passkey
    {
        /** @var Passkey $passkey */
        return $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => $credentialId,
            'credential' => ['id' => $credentialId],
        ]);
    }
}

class ScopedPasskeyUser extends User
{
    protected ?string $table = 'users';

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('hidden', static function (Builder $query): void {
            $query->whereRaw('1 = 0');
        });
    }
}
