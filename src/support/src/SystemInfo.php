<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Exception;

/**
 * System information utilities for benchmarking and diagnostics.
 *
 * Provides cross-platform detection of CPU cores, memory, and virtualization.
 * All methods fail gracefully and return null when information is unavailable.
 */
class SystemInfo
{
    /**
     * Get the number of CPU cores available.
     *
     * Supports Linux (/proc/cpuinfo), macOS (sysctl), and Windows (environment variable).
     */
    public function getCpuCores(): ?int
    {
        try {
            if (PHP_OS_FAMILY === 'Linux') {
                $cpuinfo = @file_get_contents('/proc/cpuinfo');

                if ($cpuinfo) {
                    $count = substr_count($cpuinfo, 'processor');

                    return $count > 0 ? $count : null;
                }
            } elseif (PHP_OS_FAMILY === 'Darwin') {
                $output = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');

                if ($output) {
                    return (int) trim($output);
                }
            } elseif (PHP_OS_FAMILY === 'Windows') {
                $cores = getenv('NUMBER_OF_PROCESSORS');

                if ($cores) {
                    return (int) $cores;
                }
            }
        } catch (Exception) {
            // Silently fail
        }

        return null;
    }

    /**
     * Get total system memory as a human-readable string.
     *
     * Supports Linux (/proc/meminfo), macOS (sysctl), and Windows (wmic).
     */
    public function getTotalMemory(): ?string
    {
        try {
            if (PHP_OS_FAMILY === 'Linux') {
                $meminfo = @file_get_contents('/proc/meminfo');

                if ($meminfo && preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $matches)) {
                    $kb = (int) $matches[1];

                    return Number::fileSize($kb * 1024, precision: 1);
                }
            } elseif (PHP_OS_FAMILY === 'Darwin') {
                $output = @shell_exec('sysctl -n hw.memsize 2>/dev/null');

                if ($output) {
                    return Number::fileSize((int) trim($output), precision: 1);
                }
            } elseif (PHP_OS_FAMILY === 'Windows') {
                $output = @shell_exec('wmic computersystem get totalphysicalmemory 2>nul');

                if ($output && preg_match('/\d+/', $output, $matches)) {
                    return Number::fileSize((int) $matches[0], precision: 1);
                }
            }
        } catch (Exception) {
            // Silently fail
        }

        return null;
    }

    /**
     * Detect virtualization type if running in a VM or container.
     *
     * Returns the detected type (VirtualBox, VMware, KVM, Docker, etc.) or null if not detected.
     */
    public function detectVirtualization(): ?string
    {
        try {
            if (PHP_OS_FAMILY === 'Linux') {
                // Check for common virtualization indicators
                $dmiDecodeOutput = @shell_exec('cat /sys/class/dmi/id/product_name 2>/dev/null');

                if ($dmiDecodeOutput) {
                    $product = strtolower(trim($dmiDecodeOutput));

                    if (str_contains($product, 'virtualbox')) {
                        return 'VirtualBox';
                    }

                    if (str_contains($product, 'vmware')) {
                        return 'VMware';
                    }

                    if (str_contains($product, 'kvm')) {
                        return 'KVM';
                    }

                    if (str_contains($product, 'qemu')) {
                        return 'QEMU';
                    }

                    if (str_contains($product, 'bochs')) {
                        return 'Bochs';
                    }
                }

                // Check for container
                if (file_exists('/.dockerenv')) {
                    return 'Docker';
                }

                $cgroupContent = @file_get_contents('/proc/1/cgroup');

                if ($cgroupContent && (str_contains($cgroupContent, 'docker') || str_contains($cgroupContent, 'lxc'))) {
                    return 'Container';
                }
            }
        } catch (Exception) {
            // Silently fail
        }

        return null;
    }

    /**
     * Get PHP memory limit in bytes.
     *
     * @return int Memory limit in bytes, or -1 if unlimited
     */
    public function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit') ?: '-1';

        if ($limit === '-1') {
            return -1;
        }

        $limit = strtolower(trim($limit));
        $value = (int) $limit;

        $unit = $limit[-1] ?? '';

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Get current PHP memory limit as human-readable string.
     */
    public function getMemoryLimitFormatted(): string
    {
        $bytes = $this->getMemoryLimitBytes();

        if ($bytes === -1) {
            return 'Unlimited';
        }

        return Number::fileSize($bytes, precision: 0);
    }
}
