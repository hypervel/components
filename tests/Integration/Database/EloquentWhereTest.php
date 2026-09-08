<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\MultipleRecordsFoundException;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

class EloquentWhereTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->string('address');
        });
    }

    public function testWhereAndWhereOrBehavior()
    {
        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $firstUser */
        $firstUser = UserWhereTest::create([
            'name' => 'test-name',
            'email' => 'test-email',
            'address' => 'test-address',
        ]);

        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $secondUser */
        $secondUser = UserWhereTest::create([
            'name' => 'test-name1',
            'email' => 'test-email1',
            'address' => 'test-address1',
        ]);

        $this->assertTrue($firstUser->is(UserWhereTest::where('name', '=', $firstUser->name)->first()));
        $this->assertTrue($firstUser->is(UserWhereTest::where('name', $firstUser->name)->first()));
        $this->assertTrue($firstUser->is(UserWhereTest::where('name', $firstUser->name)->where('email', $firstUser->email)->first()));
        $this->assertNull(UserWhereTest::where('name', $firstUser->name)->where('email', $secondUser->email)->first());
        $this->assertTrue($secondUser->is(UserWhereTest::where('name', 'wrong-name')->orWhere('email', $secondUser->email)->first()));
        $this->assertTrue($firstUser->is(UserWhereTest::where(['name' => 'test-name', 'email' => 'test-email'])->first()));
        $this->assertNull(UserWhereTest::where(['name' => 'test-name', 'email' => 'test-email1'])->first());
        $this->assertTrue(
            $secondUser->is(
                UserWhereTest::where(['name' => 'wrong-name', 'email' => 'test-email1'], null, null, 'or')->first()
            )
        );

        $this->assertSame(
            1,
            UserWhereTest::where(['name' => 'test-name', 'email' => 'test-email1'])
                ->orWhere(['name' => 'test-name1', 'address' => 'wrong-address'])->count()
        );

        $this->assertTrue(
            $secondUser->is(
                UserWhereTest::where(['name' => 'test-name', 'email' => 'test-email1'])
                    ->orWhere(['name' => 'test-name1', 'address' => 'wrong-address'])
                    ->first()
            )
        );
    }

    public function testWhereNot()
    {
        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $firstUser */
        $firstUser = UserWhereTest::create([
            'name' => 'test-name',
            'email' => 'test-email',
            'address' => 'test-address',
        ]);

        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $secondUser */
        $secondUser = UserWhereTest::create([
            'name' => 'test-name1',
            'email' => 'test-email1',
            'address' => 'test-address1',
        ]);

        $this->assertTrue($secondUser->is(UserWhereTest::whereNot(function ($query) use ($firstUser) {
            $query->where('name', '=', $firstUser->name);
        })->first()));
        $this->assertTrue($firstUser->is(UserWhereTest::where('name', $firstUser->name)->whereNot(function ($query) use ($secondUser) {
            $query->where('email', $secondUser->email);
        })->first()));
        $this->assertTrue($secondUser->is(UserWhereTest::where('name', 'wrong-name')->orWhereNot(function ($query) use ($firstUser) {
            $query->where('email', $firstUser->email);
        })->first()));
    }

    public function testWhereIn()
    {
        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $user1 */
        $user1 = UserWhereTest::create([
            'name' => 'test-name1',
            'email' => 'test-email1',
            'address' => 'test-address1',
        ]);

        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $user2 */
        $user2 = UserWhereTest::create([
            'name' => 'test-name2',
            'email' => 'test-email2',
            'address' => 'test-address2',
        ]);

        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $user3 */
        $user3 = UserWhereTest::create([
            'name' => 'test-name2',
            'email' => 'test-email3',
            'address' => 'test-address3',
        ]);

        $this->assertTrue($user2->is(UserWhereTest::whereIn('id', [2])->first()));

        $users = UserWhereTest::query()->whereIn('id', [1, 2, 22])->get();

        $this->assertTrue($user1->is($users[0]));
        $this->assertTrue($user2->is($users[1]));
        $this->assertCount(2, $users);

        $users = UserWhereTest::query()->whereIn('email', ['test-email1', 'test-email2'])->get();

        $this->assertTrue($user1->is($users[0]));
        $this->assertTrue($user2->is($users[1]));
        $this->assertCount(2, $users);

        $users = UserWhereTest::query()
            ->whereIn('id', [1])
            ->orWhereIn('email', ['test-email1', 'test-email2'])
            ->get();

        $this->assertTrue($user1->is($users[0]));
        $this->assertTrue($user2->is($users[1]));
        $this->assertCount(2, $users);
    }

    public function testWhereInCanAcceptQueryable()
    {
        $user1 = UserWhereTest::create([
            'name' => 'test-name1',
            'email' => 'test-email1',
            'address' => 'test-address1',
        ]);

        $user2 = UserWhereTest::create([
            'name' => 'test-name2',
            'email' => 'test-email2',
            'address' => 'test-address2',
        ]);

        $user3 = UserWhereTest::create([
            'name' => 'test-name2',
            'email' => 'test-email3',
            'address' => 'test-address3',
        ]);

        $query = UserWhereTest::query()->select('name')->where('id', '>', 1);

        $users = UserWhereTest::query()->whereIn('name', $query)->get();

        $this->assertTrue($user2->is($users[0]));
        $this->assertTrue($user3->is($users[1]));
        $this->assertCount(2, $users);

        $users = UserWhereTest::query()->whereIn('name', function (Builder $query) {
            $query->select('name')->where('id', '>', 1);
        })->get();

        $this->assertTrue($user2->is($users[0]));
        $this->assertTrue($user3->is($users[1]));
        $this->assertCount(2, $users);

        $query = DB::table('users')->select('name')->where('id', '=', 1);

        $users = UserWhereTest::query()->whereNotIn('name', $query)->get();

        $this->assertTrue($user2->is($users[0]));
        $this->assertTrue($user3->is($users[1]));
        $this->assertCount(2, $users);
    }

    public function testWhereIntegerInRaw()
    {
        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $user1 */
        $user1 = UserWhereTest::create([
            'name' => 'test-name1',
            'email' => 'test-email1',
            'address' => 'test-address1',
        ]);

        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $user2 */
        $user2 = UserWhereTest::create([
            'name' => 'test-name2',
            'email' => 'test-email2',
            'address' => 'test-address2',
        ]);

        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $user3 */
        $user3 = UserWhereTest::create([
            'name' => 'test-name2',
            'email' => 'test-email3',
            'address' => 'test-address3',
        ]);

        $users = UserWhereTest::query()->whereIntegerInRaw('id', [1, 2, 5])->get();
        $this->assertTrue($user1->is($users[0]));
        $this->assertTrue($user2->is($users[1]));
        $this->assertCount(2, $users);

        $users = UserWhereTest::query()->whereIntegerNotInRaw('id', [2])->get();
        $this->assertTrue($user1->is($users[0]));
        $this->assertTrue($user3->is($users[1]));
        $this->assertCount(2, $users);

        $users = UserWhereTest::query()->whereIntegerInRaw('id', ['1', '2'])->get();
        $this->assertTrue($user1->is($users[0]));
        $this->assertTrue($user2->is($users[1]));
        $this->assertCount(2, $users);
    }

    public function testFirstWhere()
    {
        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $firstUser */
        $firstUser = UserWhereTest::create([
            'name' => 'test-name',
            'email' => 'test-email',
            'address' => 'test-address',
        ]);

        /** @var \Hypervel\Tests\Integration\Database\UserWhereTest $secondUser */
        $secondUser = UserWhereTest::create([
            'name' => 'test-name1',
            'email' => 'test-email1',
            'address' => 'test-address1',
        ]);

        $this->assertTrue($firstUser->is(UserWhereTest::firstWhere('name', '=', $firstUser->name)));
        $this->assertTrue($firstUser->is(UserWhereTest::firstWhere('name', $firstUser->name)));
        $this->assertTrue($firstUser->is(UserWhereTest::where('name', $firstUser->name)->firstWhere('email', $firstUser->email)));
        $this->assertNull(UserWhereTest::where('name', $firstUser->name)->firstWhere('email', $secondUser->email));
        $this->assertTrue($firstUser->is(UserWhereTest::firstWhere(['name' => 'test-name', 'email' => 'test-email'])));
        $this->assertNull(UserWhereTest::firstWhere(['name' => 'test-name', 'email' => 'test-email1']));
        $this->assertTrue(
            $secondUser->is(
                UserWhereTest::firstWhere(['name' => 'wrong-name', 'email' => 'test-email1'], null, null, 'or')
            )
        );
    }

    public function testSole()
    {
        $expected = UserWhereTest::create([
            'name' => 'test-name',
            'email' => 'test-email',
            'address' => 'test-address',
        ]);

        $this->assertTrue($expected->is(UserWhereTest::where('name', 'test-name')->sole()));
    }

    public function testSoleFailsForMultipleRecords()
    {
        UserWhereTest::create([
            'name' => 'test-name',
            'email' => 'test-email',
            'address' => 'test-address',
        ]);

        UserWhereTest::create([
            'name' => 'test-name',
            'email' => 'other-email',
            'address' => 'other-address',
        ]);

        $this->expectExceptionObject(new MultipleRecordsFoundException(2));

        UserWhereTest::where('name', 'test-name')->sole();
    }

    public function testSoleFailsIfNoRecords()
    {
        try {
            UserWhereTest::where('name', 'test-name')->sole();
        } catch (ModelNotFoundException $exception) {
        }

        $this->assertSame(UserWhereTest::class, $exception->getModel());
    }

    public function testSoleValue()
    {
        $expected = UserWhereTest::create([
            'name' => 'test-name',
            'email' => 'test-email',
            'address' => 'test-address',
        ]);

        $this->assertEquals('test-name', UserWhereTest::where('name', 'test-name')->soleValue('name'));
    }

    public function testExpressionValuesPreserveSqlAndModelAttributeAccess(): void
    {
        UserWhereTest::create(['name' => 'Taylor', 'email' => 'taylor@example.com', 'address' => 'Main Street']);

        foreach ([0, 1.5, 'id + 1', 'id + 1 as total'] as $index => $value) {
            $expression = new Expression($value);
            $expected = [0, 1.5, 2, 2][$index];

            $this->assertEquals($expected, UserWhereTest::query()->value($expression));
            $this->assertEquals($expected, UserWhereTest::query()->soleValue($expression));
            $this->assertEquals($expected, UserWhereTest::query()->valueOrFail($expression));
            $this->assertEquals([$expected], UserWhereTest::query()->pluck($expression)->all());
        }

        $this->assertSame(2.0, UserWhereTest::query()->withCasts(['total' => 'float'])->value(new Expression('id + 1 as total')));
        $this->assertSame([2.0], UserWhereTest::query()->withCasts(['total' => 'float'])->pluck(new Expression('id + 1 as total'))->all());
        $this->assertSame(['Taylor' => 2.0], UserWhereTest::query()->withCasts(['total' => 'float'])->pluck(
            new Expression('id + 1 AS ' . DB::connection()->getQueryGrammar()->wrap('total')),
            'name'
        )->all());
        $this->assertSame([], UserWhereTest::query()->where('id', 0)->pluck(new Expression('id + 1 as total'))->all());
        $this->assertSame('Taylor', UserWhereTest::query()->select('name')->value(new Expression(1)));

        $model = new class extends UserWhereTest {
            public function getAttribute(string $key): mixed
            {
                return $key === 'total' ? 'Total: ' . parent::getAttribute($key) : parent::getAttribute($key);
            }
        };

        $this->assertSame('Total: 2', $model->newQuery()->value(new Expression('id + 1 as total')));
    }

    public function testExpressionPluckAppliesAccessorsAndBothQueryCallbackLayers(): void
    {
        UserWhereTest::create(['name' => 'Taylor', 'email' => 'taylor@example.com', 'address' => 'Main Street']);

        $model = new class extends UserWhereTest {
            /**
             * Format the computed total.
             */
            public function getTotalAttribute(int $value): string
            {
                return 'Total: ' . $value;
            }
        };

        $query = $model->newQuery();
        $query->getQuery()->afterQuery(fn (Collection $values): Collection => $values->map(fn (int $value): int => $value + 1));
        $query->afterQuery(fn (Collection $values): Collection => $values->map(fn (string $value): string => $value . '!'));

        $this->assertSame(['Taylor' => 'Total: 3!'], $query->pluck(new Expression('id + 1 as total'), 'name')->all());
    }

    public function testChunkMap()
    {
        UserWhereTest::create([
            'name' => 'first-name',
            'email' => 'first-email',
            'address' => 'first-address',
        ]);

        UserWhereTest::create([
            'name' => 'second-name',
            'email' => 'second-email',
            'address' => 'second-address',
        ]);

        DB::enableQueryLog();

        $results = UserWhereTest::orderBy('id')->chunkMap(function ($user) {
            return $user->name;
        }, 1);

        $this->assertCount(2, $results);
        $this->assertSame('first-name', $results[0]);
        $this->assertSame('second-name', $results[1]);
        $this->assertCount(3, DB::getQueryLog());
    }
}

class UserWhereTest extends Model
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public bool $timestamps = false;
}
