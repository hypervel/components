<?php

namespace MacroableForwardsCalls;

use App\UserResource;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Cache\TaggedCache;
use Hypervel\Database\DatabaseManager;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Http\Client\Factory as HttpClientFactory;
use Hypervel\Http\Client\Response as HttpClientResponse;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Testing\TestResponse;

use function PHPStan\Testing\assertType;

function test(
    CacheRepository $cacheRepository,
    TaggedCache $taggedCache,
    DatabaseManager $databaseManager,
    FilesystemAdapter $filesystemAdapter,
    HttpClientFactory $httpClientFactory,
    HttpClientResponse $httpClientResponse,
    ProcessFactory $processFactory,
    TestResponse $testResponse,
    UserResource $userResource,
): void {
    assertType('string', $databaseManager->getDriverName());
    assertType('int', $filesystemAdapter->fileSize('file.txt'));
    // Hypervel: PendingRequest is generic over whether it sends asynchronously.
    assertType('Hypervel\Http\Client\PendingRequest<false>', $httpClientFactory->withHeaders([]));
    assertType('string', $httpClientResponse->getProtocolVersion());
    assertType('Hypervel\Process\PendingProcess', $processFactory->path('/tmp'));
    assertType('bool', $testResponse->isOk());

    assertType('Hypervel\Contracts\Cache\Store', $taggedCache->cacheRepositoryMacro());

    assertType('App\UserResource', $userResource->jsonResourceMacro());
    assertType('string', $userResource->getTable());

    assertType('Hypervel\Contracts\Cache\Store', $cacheRepository->cacheRepositoryMacro());
    assertType('*ERROR*', $processFactory->doesNotExist());
}
