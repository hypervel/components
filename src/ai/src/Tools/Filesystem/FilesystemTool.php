<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Approvals\Approval;
use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Support\Facades\Storage;

abstract class FilesystemTool implements Approvable, Tool, Transient
{
    use InteractsWithApprovals;

    /**
     * Create a filesystem tool for the given disk.
     */
    public function __construct(protected Filesystem|string|null $disk = null)
    {
    }

    /**
     * Determine whether the given path points to a file, not a directory.
     */
    protected function fileExists(Filesystem $disk, string $path): bool
    {
        return $disk->fileExists($path);
    }

    /**
     * Resolve the filesystem disk the tool operates on.
     */
    protected function disk(): Filesystem
    {
        return $this->disk instanceof Filesystem
            ? $this->disk
            : Storage::disk($this->disk);
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
