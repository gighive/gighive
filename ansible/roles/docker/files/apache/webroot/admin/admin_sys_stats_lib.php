<?php declare(strict_types=1);

/**
 * Read a snapshot of /proc/stat CPU counters.
 *
 * Returns an array with keys:
 *   total     — sum of all jiffies across all states
 *   idle      — sum of idle + iowait jiffies
 *   cpu_count — number of logical CPU cores found
 *
 * Returns null if /proc/stat is unavailable or unparseable.
 */
function readProcCpuSample(): ?array
{
    $raw = @file_get_contents('/proc/stat');
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $total = null;
    $idle = null;
    $cpuCount = 0;
    foreach (explode("\n", $raw) as $line) {
        if ($total === null && preg_match('/^cpu\s+(.+)$/', $line, $m)) {
            $parts = preg_split('/\s+/', trim($m[1]));
            if (is_array($parts) && count($parts) >= 4) {
                $vals = array_map('intval', $parts);
                $total = array_sum($vals);
                $idle = ($vals[3] ?? 0) + ($vals[4] ?? 0);
            }
            continue;
        }
        if (preg_match('/^cpu\d+\s+/', $line)) {
            $cpuCount++;
        }
    }

    if ($total === null || $idle === null) {
        return null;
    }

    return ['total' => $total, 'idle' => $idle, 'cpu_count' => max(1, $cpuCount)];
}

/**
 * Read cumulative container CPU usage in microseconds from cgroup v2 or v1.
 *
 * Tries cgroup v2 (/sys/fs/cgroup/cpu.stat usage_usec) first, then falls
 * back to cgroup v1 (/sys/fs/cgroup/cpuacct/cpuacct.usage, which is in
 * nanoseconds and converted to microseconds).
 *
 * Returns null if neither cgroup path is readable.
 */
function readCgroupCpuUsageUsec(): ?int
{
    $v2 = @file('/sys/fs/cgroup/cpu.stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($v2)) {
        foreach ($v2 as $line) {
            if (preg_match('/^usage_usec\s+(\d+)$/', trim($line), $m)) {
                return (int) $m[1];
            }
        }
    }

    $v1 = @file_get_contents('/sys/fs/cgroup/cpuacct/cpuacct.usage');
    if (is_string($v1) && preg_match('/^(\d+)$/', trim($v1), $m)) {
        return (int) floor(((int) $m[1]) / 1000);
    }

    return null;
}
