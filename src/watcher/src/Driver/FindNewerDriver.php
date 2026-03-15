<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Support\Str;
use Hypervel\Watcher\Option;
use InvalidArgumentException;

class FindNewerDriver extends AbstractDriver
{
    protected string $tmpFile = '/tmp/hypervel_find.php';

    protected bool $scanning = false;

    protected int $count = 0;

    public function __construct(protected Option $option)
    {
        parent::__construct($option);
        $ret = $this->exec('which find');
        if (empty($ret['output'])) {
            throw new InvalidArgumentException('find not exists.');
        }
        // Create two reference files for -newer comparisons.
        $this->exec('echo 1 > ' . $this->getToModifyFile());
        $this->exec('echo 1 > ' . $this->getToScanFile());
    }

    /**
     * Watch for file changes using `find -newer`.
     */
    public function watch(Channel $channel): void
    {
        $seconds = $this->option->getScanIntervalSeconds();
        $this->timerId = $this->timer->tick($seconds, function () use ($channel) {
            if ($this->scanning) {
                return;
            }
            $this->scanning = true;
            $changedFiles = $this->scan();
            ++$this->count;
            // Update reference file mtimes after detecting changes.
            if ($changedFiles) {
                $this->exec('echo 1 > ' . $this->getToModifyFile());
                $this->exec('echo 1 > ' . $this->getToScanFile());
            }

            foreach ($changedFiles as $file) {
                $channel->push($file);
                $this->scanning = false;
                return;
            }
            $this->scanning = false;
        });
    }

    /**
     * Find files newer than the reference file in the given targets.
     */
    protected function find(array $targets, array $ext = []): array
    {
        $changedFiles = [];

        $shell = '';
        $len = count($targets);
        for ($i = 0; $i < $len; ++$i) {
            $dest = $targets[$i];
            $symbol = ($i === $len - 1) ? '' : '&';
            $file = $this->getToScanFile();
            $shell = $shell . sprintf('find %s -newer %s -type f', $dest, $file) . $symbol;
        }

        $ret = $this->exec($shell);
        if ($ret['code'] === 0 && strlen($ret['output'])) {
            $stdout = $ret['output'];
            $lineArr = explode(PHP_EOL, $stdout);
            foreach ($lineArr as $pathName) {
                if (empty($pathName)) {
                    continue;
                }

                if (! empty($ext) && ! Str::endsWith($pathName, $ext)) {
                    continue;
                }
                $changedFiles[] = $pathName;
            }
        }

        return $changedFiles;
    }

    /**
     * Scan watched directories and files for changes.
     */
    protected function scan(): array
    {
        $ext = $this->option->getExt();
        $dirs = array_map(fn ($dir) => BASE_PATH . '/' . $dir, $this->option->getWatchDir());
        $files = array_map(fn ($file) => BASE_PATH . '/' . $file, $this->option->getWatchFile());

        if ($files) {
            $dirs[] = implode(' ', $files);
        }

        return $this->find($dirs, $ext);
    }

    /**
     * Get the path to the reference file to be modified.
     */
    protected function getToModifyFile(): string
    {
        return $this->tmpFile . ($this->count % 2);
    }

    /**
     * Get the path to the reference file used for scanning.
     */
    protected function getToScanFile(): string
    {
        return $this->tmpFile . (($this->count + 1) % 2);
    }
}
