<?php

declare(strict_types=1);

/**
 * Exercise the standalone release validator with controlled evidence, including
 * failure cases. This self-test never claims production benchmark certification.
 */
function acceptanceFixtures(string $directory, bool $hosted = false): void
{
    foreach (['2.6', 'dependency-only', '3.0-unbound', '3.0-host-only', '3.0-bound'] as $variant) {
        for ($trial = 1; $trial <= 7; $trial++) {
            $workloads = [];
            foreach ([
                'request_host_cold' => [1, 1],
                'request_host_warm_c1' => [1, 1_000],
                'request_host_warm_c2' => [2, 2_000],
                'request_host_warm_c4' => [4, 4_000],
                'request_host_expected_failure' => [1, 1_000],
                'durable_delivery' => [2, 2_000],
            ] as $name => [$concurrency, $attempted]) {
                $workloads[] = [
                    'name' => $name,
                    'concurrency' => $concurrency,
                    'duration_seconds' => 5.0,
                    'metadata' => [
                        'host_processes_peak' => 5,
                        'expected_http_status' => $name === 'request_host_expected_failure' ? 503 : 200,
                        'validated_output' => true,
                        'queue_depth_after' => 0,
                        'connections' => 2,
                    ],
                    'result' => [
                        'attempted_operations' => $attempted,
                        'successful_operations' => $attempted,
                        'failed_operations' => 0,
                        'timeouts' => 0,
                        'successful_rpm' => match ($variant) {
                            '3.0-unbound' => 297_000,
                            '3.0-host-only' => 250_000,
                            '3.0-bound' => 247_500,
                            default => 300_000,
                        },
                        'latency_ms' => ['p50' => 1.0, 'p95' => 2.0, 'p99' => 3.0],
                        'cpu' => ['seconds_per_successful_operation' => 0.0001],
                        'memory' => ['peak_mb' => 180.0, 'growth_mb' => 0.02],
                    ],
                ];
            }
            $revision = str_starts_with($variant, '3.0-') ? 'candidate-revision' : 'baseline-revision';
            file_put_contents(
                "$directory/$variant-$trial.json",
                json_encode([
                    'environment' => [
                        'stable' => !$hosted,
                        'runner_environment' => $hosted ? 'github-hosted' : 'self-hosted',
                        'release' => $variant,
                        'http_server_workers' => 4,
                        'source_revision' => $revision,
                        'fingerprint' => 'identical-test-machine',
                    ],
                    'workloads' => $workloads,
                ], JSON_THROW_ON_ERROR),
            );
        }
    }
}

/** @return array{exit:int,output:string} */
function validateAcceptance(string $directory, string $mode = 'dedicated'): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/release-acceptance.php', $directory, $mode],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run release validator.');
    }

    $output = (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'output' => $output];
}

/** @param Closure(array<string,mixed>&):void $change */
function assertRejected(string $directory, Closure $change, string $reason): void
{
    acceptanceFixtures($directory);
    $file = "$directory/3.0-unbound-1.json";
    $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $change($document);
    file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR));
    $result = validateAcceptance($directory);
    if ($result['exit'] === 0 || !str_contains($result['output'], $reason)) {
        throw new RuntimeException('Validator did not reject ' . $reason . ': ' . $result['output']);
    }
}

function setAcceptanceRpm(string $directory, string $variant, float $rpm): void
{
    for ($trial = 1; $trial <= 7; $trial++) {
        $file = "$directory/$variant-$trial.json";
        $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $document['workloads'][3]['result']['successful_rpm'] = $rpm;
        file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR));
    }
}

$directory = sys_get_temp_dir() . '/omnibus-release-selftest-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create release self-test directory.');
}

try {
    acceptanceFixtures($directory);
    $pass = validateAcceptance($directory);
    if ($pass['exit'] !== 0) {
        throw new RuntimeException('Valid certification evidence rejected: ' . $pass['output']);
    }
    $report = json_decode($pass['output'], true, 512, JSON_THROW_ON_ERROR);
    if (($report['passed'] ?? false) !== true
        || count($report['workloads']['3.0-unbound'] ?? []) !== 6
        || ($report['comparisons'][0]['limits']['rpm_regression_percent'] ?? null) !== 2
        || ($report['comparisons'][1]['limits']['rpm_regression_percent'] ?? null) !== 3
        || ($report['budget_policy'] ?? null) !== 'omnibus-3.0-scoped-runwire-2026-10-08') {
        throw new RuntimeException('Release report lost per-workload evidence.');
    }
    setAcceptanceRpm($directory, '3.0-bound', 243_750);
    $acceptedCost = validateAcceptance($directory);
    if ($acceptedCost['exit'] !== 0) {
        throw new RuntimeException('Approved 2.5% binding overhead rejected: ' . $acceptedCost['output']);
    }
    setAcceptanceRpm($directory, '3.0-unbound', 292_500);
    $unboundCost = validateAcceptance($directory);
    if ($unboundCost['exit'] === 0 || !str_contains($unboundCost['output'], 'exceeds 2.0%')) {
        throw new RuntimeException('Binding trade-off weakened unbound 2% budget: ' . $unboundCost['output']);
    }
    acceptanceFixtures($directory);
    setAcceptanceRpm($directory, '3.0-bound', 242_250);
    $excessBindingCost = validateAcceptance($directory);
    if ($excessBindingCost['exit'] === 0 || !str_contains($excessBindingCost['output'], 'exceeds 3.0%')) {
        throw new RuntimeException('Binding overhead exceeded its 3% cap: ' . $excessBindingCost['output']);
    }
    acceptanceFixtures($directory, true);
    $hosted = validateAcceptance($directory, 'hosted');
    if ($hosted['exit'] !== 0) {
        throw new RuntimeException('Valid matched hosted-runner evidence rejected: ' . $hosted['output']);
    }
    $hostedReport = json_decode($hosted['output'], true, 512, JSON_THROW_ON_ERROR);
    if (($hostedReport['certification_scope'] ?? null) !== 'github-hosted-matched'
        || ($hostedReport['environment_stable'] ?? null) !== false) {
        throw new RuntimeException('Hosted evidence incorrectly presented as dedicated certification.');
    }
    if (validateAcceptance($directory)['exit'] === 0) {
        throw new RuntimeException('Dedicated mode accepted hosted evidence.');
    }
    $short = "$directory/3.0-unbound-1.json";
    $shortDocument = json_decode((string) file_get_contents($short), true, 512, JSON_THROW_ON_ERROR);
    $shortDocument['workloads'][3]['duration_seconds'] = 0.45;
    file_put_contents($short, json_encode($shortDocument, JSON_THROW_ON_ERROR));
    $shortResult = validateAcceptance($directory, 'hosted');
    if ($shortResult['exit'] === 0 || !str_contains($shortResult['output'], 'measurement window')) {
        throw new RuntimeException('Hosted mode accepted a sub-second throughput window.');
    }
    acceptanceFixtures($directory);
    if (validateAcceptance($directory, 'hosted')['exit'] === 0) {
        throw new RuntimeException('Hosted mode accepted dedicated evidence.');
    }
    assertRejected($directory, static function (array &$document): void {
        array_pop($document['workloads']);
    }, 'Missing or unexpected benchmark workload');
    assertRejected($directory, static function (array &$document): void {
        $document['workloads'][0] = $document['workloads'][1];
    }, 'Unknown or duplicate benchmark workload');
    assertRejected($directory, static function (array &$document): void {
        $document['workloads'][2]['result']['timeouts'] = 1;
    }, 'Benchmark workload correctness');
    assertRejected($directory, static function (array &$document): void {
        $document['workloads'][4]['metadata']['expected_http_status'] = 200;
    }, 'Expected-failure HTTP response');
    assertRejected($directory, static function (array &$document): void {
        $document['workloads'][5]['metadata']['queue_depth_after'] = 1;
    }, 'Durable queue did not drain');
    assertRejected($directory, static function (array &$document): void {
        $document['workloads'][3]['result']['latency_ms']['p95'] = -1;
    }, 'Invalid benchmark latency');
    acceptanceFixtures($directory);
    setAcceptanceRpm($directory, '3.0-unbound', 270_000);
    $failed = validateAcceptance($directory);
    if ($failed['exit'] === 0 || !str_contains($failed['output'], 'rpm_regression_percent')) {
        throw new RuntimeException('2% release throughput budget was bypassed: ' . $failed['output']);
    }

    fwrite(STDOUT, 'Release validator self-test: passed (runner-mode separation, malformed evidence, scoped binding cost, and unbound throughput regression).' . PHP_EOL);
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
