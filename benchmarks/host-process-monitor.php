<?php

declare(strict_types=1);

/** @return list<int> */
function observedPids(int $root): array
{
    $pending = [$root];
    $visited = [];
    while ($pending !== []) {
        $pid = array_pop($pending);
        if (!is_int($pid) || isset($visited[$pid]) || !is_dir('/proc/' . $pid)) {
            continue;
        }
        $visited[$pid] = true;
        $path = sprintf('/proc/%d/task/%d/children', $pid, $pid);
        $text = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($text) || trim($text) === '') {
            continue;
        }
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $child) {
            if (ctype_digit($child)) {
                $pending[] = (int) $child;
            }
        }
    }

    return array_keys($visited);
}

/** @return array{rss_bytes:int,cpu_nanoseconds:int,processes:int} */
function processSample(int $root): array
{
    $rss = $cpu = 0;
    $pids = observedPids($root);
    foreach ($pids as $pid) {
        $statusPath = sprintf('/proc/%d/status', $pid);
        $status = is_readable($statusPath) ? file_get_contents($statusPath) : false;
        if (is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $match) === 1) {
            $rss += (int) $match[1] * 1024;
        }
        $schedPath = sprintf('/proc/%d/schedstat', $pid);
        $sched = is_readable($schedPath) ? file_get_contents($schedPath) : false;
        if (is_string($sched) && preg_match('/^(\d+)/', $sched, $match) === 1) {
            $cpu += (int) $match[1];
        }
    }

    return ['rss_bytes' => $rss, 'cpu_nanoseconds' => $cpu, 'processes' => count($pids)];
}

$root = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT);
$stopFile = $argv[2] ?? '';
$report = $argv[3] ?? '';
if (!is_int($root) || $root < 1 || $stopFile === '' || $report === '') {
    throw new InvalidArgumentException('Expected server PID, stop-marker and report file.');
}

$samples = [];
while (!is_file($stopFile)) {
    $samples[] = ['at' => hrtime(true)] + processSample($root);
    usleep(20_000);
}
$samples[] = ['at' => hrtime(true)] + processSample($root);
if (count($samples) < 2) {
    throw new RuntimeException('Host sampler did not capture two measurements.');
}
$first = $samples[0];
$last = $samples[count($samples) - 1];
$elapsed = max(0.000001, ($last['at'] - $first['at']) / 1_000_000_000);
$cpuSeconds = max(0, $last['cpu_nanoseconds'] - $first['cpu_nanoseconds']) / 1_000_000_000;
$peakRss = max(array_column($samples, 'rss_bytes'));
$averageRss = array_sum(array_column($samples, 'rss_bytes')) / count($samples);
$maxProcesses = max(array_column($samples, 'processes'));
if ($maxProcesses < 1 || $peakRss <= 0) {
    throw new RuntimeException('No live host process RSS was observed.');
}
file_put_contents($report, json_encode([
    'elapsed_seconds' => $elapsed,
    'cpu_seconds' => $cpuSeconds,
    'average_rss_bytes' => $averageRss,
    'peak_rss_bytes' => $peakRss,
    'rss_growth_bytes' => $last['rss_bytes'] - $first['rss_bytes'],
    'peak_process_count' => $maxProcesses,
    'sampling_interval_ms' => 20,
    'samples' => count($samples),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
