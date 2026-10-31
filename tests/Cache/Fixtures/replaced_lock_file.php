<?php

declare(strict_types=1);

namespace Hypervel\Filesystem {
    /**
     * Replace the lock after opening its file but before taking its exclusive lock.
     *
     * @param resource $stream
     */
    function flock($stream, int $operation): bool
    {
        if (($operation & LOCK_EX) !== 0 && isset($GLOBALS['replaceLockFile'])) {
            $replace = $GLOBALS['replaceLockFile'];
            unset($GLOBALS['replaceLockFile']);
            $replace();
        }

        return \flock($stream, $operation);
    }
}

namespace {
    use Hypervel\Cache\FileStore;
    use Hypervel\Filesystem\Filesystem;
    use Hypervel\Support\Facades\Date;

    require $argv[1];

    $store = new FileStore(new Filesystem, $argv[2]);
    $operation = $argv[3];
    $original = $store->lock('key', $operation === 'acquire' ? 1 : 60, 'original');
    $replacement = $store->lock('key', 60, 'replacement');

    if (! $original->acquire()) {
        throw new RuntimeException('Unable to acquire the original lock.');
    }

    if ($operation === 'acquire') {
        Date::setTestNow(Date::now()->addSeconds(5));
    }

    $GLOBALS['replaceLockFile'] = static function () use ($original, $replacement): void {
        $original->release();

        if (! $replacement->acquire()) {
            throw new RuntimeException('Unable to replace the lock.');
        }
    };

    echo json_encode([
        'succeeded' => match ($operation) {
            'acquire' => $store->lock('key', 60, 'contender')->acquire(),
            'refresh' => $original->refresh(60),
        },
        'replacementOwned' => $replacement->isOwnedByCurrentProcess(),
    ], JSON_THROW_ON_ERROR);
}
