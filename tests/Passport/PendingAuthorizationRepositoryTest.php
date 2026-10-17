<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use Hypervel\Passport\PendingAuthorizationRepository;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;

class PendingAuthorizationRepositoryTest extends TestCase
{
    public function testConsumingOneDecisionPreservesOtherTabsAndRejectsReplay(): void
    {
        $repository = $this->repository();
        $request = $this->request();
        $first = $repository->store($request, ['client_id' => 'first']);
        $second = $repository->store($request, ['client_id' => 'second']);

        $this->assertSame('first', $repository->pull($request, $first)['client_id']);

        try {
            $repository->pull($request, $first);
            $this->fail('An authorization may only be consumed once.');
        } catch (InvalidAuthTokenException) {
            $this->assertSame('second', $repository->get($request, $second)['client_id']);
        }
    }

    public function testUpdatingAnInteractionDoesNotExtendItsLifetime(): void
    {
        $repository = $this->repository();
        $request = $this->request();
        $id = $repository->store($request, ['client_id' => 'first']);
        $expiresAt = $repository->get($request, $id)['expires_at'];
        $this->travel(9)->minutes();

        $repository->update($request, $id, ['confirmed' => true, 'expires_at' => Date::now()->addHour()->getTimestamp()]);
        $this->assertTrue($repository->get($request, $id)['confirmed']);
        $this->assertSame($expiresAt, $repository->get($request, $id)['expires_at']);

        $this->travel(1)->minute();
        $this->expectException(InvalidAuthTokenException::class);
        $repository->get($request, $id);
    }

    public function testPendingLimitEvictsTheOldestRequest(): void
    {
        $repository = $this->repository();
        $request = $this->request();
        $first = $repository->store($request, ['client_id' => 'first']);
        $second = $repository->store($request, ['client_id' => 'second']);
        $third = $repository->store($request, ['client_id' => 'third']);

        $this->assertSame('second', $repository->get($request, $second)['client_id']);
        $this->assertSame('third', $repository->get($request, $third)['client_id']);
        $this->expectException(InvalidAuthTokenException::class);
        $repository->get($request, $first);
    }

    /**
     * Create the repository with a small pending-request limit.
     */
    protected function repository(): PendingAuthorizationRepository
    {
        return new PendingAuthorizationRepository(new Repository([
            'passport' => ['authorization' => ['lifetime' => 600, 'max_pending' => 2]],
        ]));
    }

    /**
     * Create a browser request with its own session.
     */
    protected function request(): Request
    {
        $request = Request::create('/oauth/authorize');
        $request->setHypervelSession(new Store('passport', new ArraySessionHandler(120)));

        return $request;
    }
}
