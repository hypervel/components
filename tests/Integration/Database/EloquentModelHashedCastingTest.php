<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Schema;
use RuntimeException;

class EloquentModelHashedCastingTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('hashed_casts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('password')->nullable();
        });
    }

    /**
     * Apply hashing configuration and rebuild resolved drivers for the current test.
     *
     * @param array<string, int|string> $configurations
     */
    protected function configureHashing(array $configurations): void
    {
        foreach ($configurations as $key => $value) {
            Config::set($key, $value);
        }

        $this->app->make('hash')->forgetDrivers();
    }

    public function testHashedWithBcrypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 13,
        ]);

        $subject = HashedCast::create([
            'password' => 'password',
        ]);

        $this->assertTrue(password_verify('password', $subject->password));
        $this->assertSame('2y', password_get_info($subject->password)['algo']);
        $this->assertSame(13, password_get_info($subject->password)['options']['cost']);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => $subject->password,
        ]);
    }

    public function testNotHashedIfAlreadyHashedWithBcrypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 13,
        ]);

        $subject = HashedCast::create([
            // "password"; 13 rounds; bcrypt;
            'password' => '$2y$13$Hdxlvi7OZqK3/fKVNypJs.vJqQcmOo3HnnT6w7fec9FRTRYxAhuCO',
        ]);

        $this->assertSame('$2y$13$Hdxlvi7OZqK3/fKVNypJs.vJqQcmOo3HnnT6w7fec9FRTRYxAhuCO', $subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => '$2y$13$Hdxlvi7OZqK3/fKVNypJs.vJqQcmOo3HnnT6w7fec9FRTRYxAhuCO',
        ]);
    }

    public function testNotHashedIfNullWithBrcypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 13,
        ]);

        $subject = HashedCast::create([
            'password' => null,
        ]);

        $this->assertNull($subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => null,
        ]);
    }

    public function testPassingHashWithHigherCostThrowsExceptionWithBcrypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not verify the hashed value's configuration.");

        $subject = HashedCast::create([
            // "password"; 13 rounds; bcrypt;
            'password' => '$2y$13$Hdxlvi7OZqK3/fKVNypJs.vJqQcmOo3HnnT6w7fec9FRTRYxAhuCO',
        ]);
    }

    public function testPassingHashWithLowerCostDoesNotThrowExceptionWithBcrypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 13,
        ]);

        $subject = HashedCast::create([
            // "password"; 7 rounds; bcrypt;
            'password' => '$2y$07$Ivc2VnUOUFtfdbXFc/Ysu.PgiwAHkDmbZQNR1OpIjKCxTxEfWLP5y',
        ]);

        $this->assertSame('$2y$07$Ivc2VnUOUFtfdbXFc/Ysu.PgiwAHkDmbZQNR1OpIjKCxTxEfWLP5y', $subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => '$2y$07$Ivc2VnUOUFtfdbXFc/Ysu.PgiwAHkDmbZQNR1OpIjKCxTxEfWLP5y',
        ]);
    }

    public function testPassingDifferentHashAlgorithmThrowsExceptionWithBcrypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 13,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not verify the hashed value's configuration.");

        $subject = HashedCast::create([
            // "password"; argon2id;
            'password' => '$argon2i$v=19$m=1024,t=2,p=2$OENON0I5bXo2WDQyQnM2bg$3ma8cKHITsmAjyIYKDLdSvtkMCiEz/s6qWnLAf+Ehek',
        ]);
    }

    public function testHashedWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 1234,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $subject = HashedCast::create([
            'password' => 'password',
        ]);

        $this->assertTrue(password_verify('password', $subject->password));
        $this->assertSame('argon2i', password_get_info($subject->password)['algo']);
        $this->assertSame(1234, password_get_info($subject->password)['options']['memory_cost']);
        $this->assertSame(2, password_get_info($subject->password)['options']['threads']);
        $this->assertSame(7, password_get_info($subject->password)['options']['time_cost']);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => $subject->password,
        ]);
    }

    public function testNotHashedIfAlreadyHashedWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 1234,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $subject = HashedCast::create([
            // "password"; 1234 memory; 2 threads; 7 time; argon2i;
            'password' => '$argon2i$v=19$m=1234,t=7,p=2$Lm9vSkJuU3M1SllaaTNwZA$5izrDfbWtpkSBH9EczQ8U1yjSOvAkhE4AuYrbBHwi5k',
        ]);

        $this->assertSame('$argon2i$v=19$m=1234,t=7,p=2$Lm9vSkJuU3M1SllaaTNwZA$5izrDfbWtpkSBH9EczQ8U1yjSOvAkhE4AuYrbBHwi5k', $subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => '$argon2i$v=19$m=1234,t=7,p=2$Lm9vSkJuU3M1SllaaTNwZA$5izrDfbWtpkSBH9EczQ8U1yjSOvAkhE4AuYrbBHwi5k',
        ]);
    }

    public function testNotHashedIfNullWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 1234,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $subject = HashedCast::create([
            'password' => null,
        ]);

        $this->assertNull($subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => null,
        ]);
    }

    public function testPassingHashWithHigherMemoryThrowsExceptionWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 1234,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not verify the hashed value's configuration.");

        $subject = HashedCast::create([
            // "password"; 2345 memory; 2 threads; 7 time; argon2i;
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);
    }

    public function testPassingHashWithHigherTimeThrowsExceptionWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 1234,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not verify the hashed value's configuration.");

        $subject = HashedCast::create([
            // "password"; 1234 memory; 2 threads; 8 time; argon2i;
            'password' => '$argon2i$v=19$m=1234,t=8,p=2$LmszcGVHd0t6b3JweUxqTQ$sdY25X0Qe86fezr1cEjYQxAHI2SdN67yVs5x0ovffag',
        ]);
    }

    public function testPassingHashWithHigherThreadsThrowsExceptionWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 1234,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not verify the hashed value's configuration.");

        $subject = HashedCast::create([
            // "password"; 1234 memory; 3 threads; 7 time; argon2i;
            'password' => '$argon2i$v=19$m=1234,t=7,p=3$OFludXF6bzFpRmdpSHdwSA$J1P4dCGJde6mYe2RZEOFWaztBbDWfxQAM09ZQRMjsw8',
        ]);
    }

    public function testPassingHashWithLowerMemoryThrowsExceptionWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 3456,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $subject = HashedCast::create([
            // "password"; 2345 memory; 2 threads; 7 time; argon2i;
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);

        $this->assertSame('$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k', $subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);
    }

    public function testPassingHashWithLowerTimeThrowsExceptionWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 2345,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 8,
        ]);

        $subject = HashedCast::create([
            // "password"; 2345 memory; 2 threads; 7 time; argon2i;
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);

        $this->assertSame('$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k', $subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);
    }

    public function testPassingHashWithLowerThreadsThrowsExceptionWithArgon()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.argon.memory' => 2345,
            'hashing.argon.threads' => 3,
            'hashing.argon.time' => 7,
        ]);

        $subject = HashedCast::create([
            // "password"; 2345 memory; 2 threads; 7 time; argon2i;
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);

        $this->assertSame('$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k', $subject->password);
        $this->assertDatabaseHas('hashed_casts', [
            'id' => $subject->id,
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);
    }

    public function testPassingDifferentHashAlgorithmThrowsExceptionWithArgonAndBcrypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon',
            'hashing.bcrypt.rounds' => 13,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not verify the hashed value's configuration.");

        $subject = HashedCast::create([
            // "password"; bcrypt;
            'password' => '$2y$13$Hdxlvi7OZqK3/fKVNypJs.vJqQcmOo3HnnT6w7fec9FRTRYxAhuCO',
        ]);
    }

    public function testPassingDifferentHashAlgorithmThrowsExceptionWithArgon2idAndBcrypt()
    {
        $this->configureHashing([
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 2345,
            'hashing.argon.threads' => 2,
            'hashing.argon.time' => 7,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not verify the hashed value's configuration.");

        $subject = HashedCast::create([
            // "password"; 2345 memory; 2 threads; 7 time; argon2i;
            'password' => '$argon2i$v=19$m=2345,t=7,p=2$MWVVZnpiZHl5RkcveHovcA$QECQzuQ2aAKvUpD25cTUJaAyPFxlOIsCRu+5nbDsU3k',
        ]);
    }
}

class HashedCast extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public array $casts = [
        'password' => 'hashed',
    ];
}
