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
$mode = $argv[2] ?? 'dedicated';
if (!in_array($mode, ['dedicated', 'hosted'], true)) {
    throw new InvalidArgumentException('Expected dedicated or hosted benchmark mode.');
}
if (!is_dir($directory)) {
    throw new InvalidArgumentException('Expected directory containing matched benchmark trial JSON.');
}
$results = [];
$fingerprint = null;
$runnerEnvironment = null;
$revisions = [];
$failures = [];
foreach (['2.6', 'dependency-only', '3.0-unbound', '3.0-host-only', '3.0-bound'] as $variant) {
    $files = glob($directory . '/' . $variant . '-*.json');
    if (!is_array($files) || count($files) !== 7) {
        throw new RuntimeException('Exactly seven independent trials required for ' . $variant);
    }
    $trials = [];
    $workloadSamples = [];
    foreach ($files as $file) {
        $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $env = $document['environment'] ?? [];
        $expectedStable = $mode === 'dedicated';
        $expectedRunner = $mode === 'hosted' ? 'github-hosted' : 'self-hosted';
        if (($env['stable'] ?? null) !== $expectedStable
            || ($env['runner_environment'] ?? null) !== $expectedRunner
            || ($env['release'] ?? '') !== $variant
            || ($env['http_server_workers'] ?? null) !== 4
            || !is_string($env['source_revision'] ?? null)
            || $env['source_revision'] === 'unlabeled') {
            throw new RuntimeException('Incorrect runner mode, unlabelled source, or host trial: ' . $file);
        }
        $runnerEnvironment = $env['runner_environment'];
        if ($fingerprint !== null && $fingerprint !== $env['fingerprint']) {
            throw new RuntimeException('Benchmark environment fingerprint changed between trials.');
        }
        $fingerprint = $env['fingerprint'];
        $revisions[$variant][$env['source_revision']] = true;
        $requiredWorkloads = [
            'request_host_cold' => [1, 1],
            'request_host_warm_c1' => [1, 1_000],
            'request_host_warm_c2' => [2, 2_000],
            'request_host_warm_c4' => [4, 4_000],
            'request_host_expected_failure' => [1, 1_000],
            'durable_delivery' => [2, 2_000],
        ];
        $workloads = $document['workloads'] ?? null;
        if (!is_array($workloads) || count($workloads) !== count($requiredWorkloads)) {
            throw new RuntimeException('Missing or unexpected benchmark workload: ' . $file);
        }
        $seen = [];
        foreach ($workloads as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            if (!is_string($name) || !isset($requiredWorkloads[$name]) || isset($seen[$name])) {
                throw new RuntimeException('Unknown or duplicate benchmark workload: ' . $file);
            }
            $seen[$name] = true;
            $result = $entry['result'] ?? null;
            [$concurrency, $minimum] = $requiredWorkloads[$name];
            if (!is_array($result) || ($entry['concurrency'] ?? null) !== $concurrency
                || !is_int($result['attempted_operations'] ?? null)
                || $result['attempted_operations'] < $minimum
                || ($result['successful_operations'] ?? null) !== $result['attempted_operations']
                || ($result['failed_operations'] ?? null) !== 0
                || ($result['timeouts'] ?? null) !== 0
                || !is_numeric($result['successful_rpm'] ?? null)
                || !is_finite((float) $result['successful_rpm'])
                || (float) $result['successful_rpm'] <= 0.0) {
                throw new RuntimeException('Benchmark workload correctness or depth failed: ' . $name);
            }
            $latencies = $result['latency_ms'] ?? [];
            if (!is_array($latencies)) {
                throw new RuntimeException('Missing benchmark latencies: ' . $name);
            }
            foreach (['p50', 'p95', 'p99'] as $percentile) {
                if (!is_numeric($latencies[$percentile] ?? null)
                    || !is_finite((float) $latencies[$percentile])
                    || (float) $latencies[$percentile] < 0.0) {
                    throw new RuntimeException('Invalid benchmark latency: ' . $name);
                }
            }
            if ($latencies['p50'] > $latencies['p95'] || $latencies['p95'] > $latencies['p99']) {
                throw new RuntimeException('Out-of-order benchmark latency percentiles: ' . $name);
            }
            if ($name === 'request_host_expected_failure'
                && (($entry['metadata']['expected_http_status'] ?? null) !== 503
                    || ($entry['metadata']['validated_output'] ?? null) !== true)) {
                throw new RuntimeException('Expected-failure HTTP response was not validated.');
            }
            if ($name === 'durable_delivery'
                && (($entry['metadata']['queue_depth_after'] ?? null) !== 0
                    || ($entry['metadata']['connections'] ?? null) !== 2)) {
                throw new RuntimeException('Durable queue did not drain on the expected connections.');
            }
            $workloadSamples[$name][] = [
                'rpm' => (float) $result['successful_rpm'],
                'p50' => (float) $latencies['p50'],
                'p95' => (float) $latencies['p95'],
                'p99' => (float) $latencies['p99'],
            ];
        }
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
        if ($mode === 'hosted' && (!is_numeric($work['duration_seconds'] ?? null)
            || (float) $work['duration_seconds'] < 3.0)) {
            throw new RuntimeException('Hosted warm c4 measurement window must be at least three seconds: ' . $file);
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
            'window_seconds' => (float) ($work['duration_seconds'] ?? 0.0),
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
    foreach ($workloadSamples as $name => $samples) {
        if (count($samples) !== 7) {
            throw new RuntimeException('Missing workload trials: ' . $name);
        }
        foreach (array_keys($samples[0]) as $metric) {
            $workloadSummaries[$variant][$name][$metric] = benchmarkMedian(array_column($samples, $metric));
        }
    }
    $measurements = [];
    foreach (array_keys($trials[0]) as $metric) {
        $measurements[$metric] = benchmarkMedian(array_column($trials, $metric));
    }
    $cv = benchmarkCv(array_column($trials, 'rpm'));
    if ($cv > 0.02) {
        $failures[] = sprintf('%s RPM CV %.2f%% exceeds matched-trial 2%%', $variant, $cv * 100);
    }
    $results[$variant] = $measurements + [
        'rpm_cv_percent' => $cv * 100,
        'trial_rpm' => array_column($trials, 'rpm'),
        'trial_windows_seconds' => array_column($trials, 'window_seconds'),
    ];
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
    'certification_scope' => $mode === 'hosted' ? 'github-hosted-matched' : 'isolated-dedicated',
    'environment_stable' => $mode === 'dedicated',
    'runner_environment' => $runnerEnvironment,
    'environment_fingerprint' => $fingerprint,
    'variants' => $results,
    'workloads' => $workloadSummaries,
    'comparisons' => $comparisons,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
if ($failures !== []) {
    throw new RuntimeException('Matched release performance failed its unwaived budgets.');
}
