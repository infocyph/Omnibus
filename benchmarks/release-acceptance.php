<?php

declare(strict_types=1);

/** @param list<float> $numbers */
function benchmarkMedian(array $numbers): float
{
    sort($numbers, SORT_NUMERIC);

    return $numbers[intdiv(count($numbers), 2)];
}

/** @param list<float> $numbers */
function benchmarkCv(array $numbers): float
{
    $mean = array_sum($numbers) / count($numbers);
    $variance = 0.0;
    foreach ($numbers as $number) {
        $variance += ($number - $mean) ** 2;
    }

    return sqrt($variance / count($numbers)) / $mean;
}

$directory = $argv[1] ?? '';
if (!is_dir($directory)) {
    throw new InvalidArgumentException('Expected directory containing stable benchmark trial JSON.');
}
$results = [];
$fingerprint = null;
$revisions = [];
$failures = [];
foreach (['2.6', 'dependency-only', '3.0-unbound', '3.0-host-only', '3.0-bound'] as $variant) {
    $files = glob($directory . '/' . $variant . '-*.json');
    if (!is_array($files) || count($files) < 7) {
        throw new RuntimeException('At least seven independent trials required for ' . $variant);
    }
    $trials = [];
    foreach ($files as $file) {
        $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $env = $document['environment'] ?? [];
        if (($env['stable'] ?? false) !== true || ($env['release'] ?? '') !== $variant
            || ($env['http_server_workers'] ?? null) !== 4
            || !is_string($env['source_revision'] ?? null)
            || $env['source_revision'] === 'unlabeled') {
            throw new RuntimeException('Unstable, unlabelled, or incorrect host trial: ' . $file);
        }
        if ($fingerprint !== null && $fingerprint !== $env['fingerprint']) {
            throw new RuntimeException('Benchmark environment fingerprint changed between trials.');
        }
        $fingerprint = $env['fingerprint'];
        $revisions[$variant][$env['source_revision']] = true;
        $matching = array_values(array_filter(
            $document['workloads'] ?? [],
            static fn(array $work): bool => ($work['name'] ?? '') === 'request_host_warm_c4',
        ));
        if (count($matching) !== 1) {
            throw new RuntimeException('Exactly one warm c4 HTTP workload is required.');
        }
        $work = $matching[0];
        $r = $work['result'];
        $attempted = $r['attempted_operations'];
        if ($attempted < 4_000 || $r['successful_operations'] !== $attempted
            || $r['failed_operations'] !== 0 || $r['timeouts'] !== 0
            || ($work['metadata']['host_processes_peak'] ?? 0) < 5) {
            throw new RuntimeException('Correct responses, throughput depth or live workers missing.');
        }
        $cpu = $r['cpu']['seconds_per_successful_operation'] ?? null;
        $rss = $r['memory']['growth_mb'] ?? null;
        if (!is_numeric($cpu) || $cpu <= 0 || !is_numeric($rss)
            || !is_numeric($r['memory']['peak_mb'] ?? null) || $r['memory']['peak_mb'] <= 0) {
            throw new RuntimeException('Missing Linux process-tree CPU/RSS measurements.');
        }
        $rssGrowth = max(0, (float) $rss) * 10_000 / $attempted;
        if ($rssGrowth > 4) {
            $failures[] = sprintf('%s grew %.3f MiB RSS per 10k correct requests', $variant, $rssGrowth);
        }
        $trials[] = [
            'rpm' => (float) $r['successful_rpm'],
            'p95' => (float) $r['latency_ms']['p95'],
            'p99' => (float) $r['latency_ms']['p99'],
            'cpu' => (float) $cpu,
            'rss_peak' => (float) $r['memory']['peak_mb'],
            'rss_growth_10k' => $rssGrowth,
        ];
    }
    if (count($revisions[$variant]) !== 1) {
        throw new RuntimeException('Multiple source revisions in one trial variant.');
    }
    $measurements = [];
    foreach (array_keys($trials[0]) as $metric) {
        $measurements[$metric] = benchmarkMedian(array_column($trials, $metric));
    }
    $cv = benchmarkCv(array_column($trials, 'rpm'));
    if ($cv > 0.02) {
        $failures[] = sprintf('%s RPM CV %.2f%% exceeds stable 2%%', $variant, $cv * 100);
    }
    $results[$variant] = $measurements + ['rpm_cv_percent' => $cv * 100];
}
if (array_keys($revisions['2.6']) !== array_keys($revisions['dependency-only'])
    || array_keys($revisions['3.0-unbound']) !== array_keys($revisions['3.0-host-only'])
    || array_keys($revisions['3.0-unbound']) !== array_keys($revisions['3.0-bound'])) {
    throw new RuntimeException('Source-revision mismatches between matched variants.');
}
$comparisons = [];
foreach ([['2.6', '3.0-unbound'], ['3.0-host-only', '3.0-bound']] as [$baseline, $candidate]) {
    $a = $results[$baseline];
    $b = $results[$candidate];
    $deltas = [
        'rpm_regression_percent' => 100 * (1 - $b['rpm'] / $a['rpm']),
        'p95_regression_percent' => 100 * ($b['p95'] / $a['p95'] - 1),
        'p99_regression_percent' => 100 * ($b['p99'] / $a['p99'] - 1),
        'cpu_regression_percent' => 100 * ($b['cpu'] / $a['cpu'] - 1),
    ];
    foreach (['rpm_regression_percent' => 2, 'p95_regression_percent' => 15, 'p99_regression_percent' => 20, 'cpu_regression_percent' => 5] as $metric => $limit) {
        if ($deltas[$metric] > $limit) {
            $failures[] = sprintf('%s vs %s: %s %.3f%% exceeds %.1f%%', $candidate, $baseline, $metric, $deltas[$metric], $limit);
        }
    }
    $comparisons[] = ['baseline' => $baseline, 'candidate' => $candidate, 'deltas' => $deltas];
}
fwrite(STDOUT, json_encode([
    'passed' => $failures === [],
    'candidate_revision' => array_key_first($revisions['3.0-unbound']),
    'environment_fingerprint' => $fingerprint,
    'variants' => $results,
    'comparisons' => $comparisons,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
if ($failures !== []) {
    exit(1);
}
