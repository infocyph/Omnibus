<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Psr\Clock\ClockInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class ContentionMessage
{
    public function __construct(public int $sequence) {}
}

$driver = $argv[1] ?? 'mysql';
$consumers = filter_var($argv[2] ?? 2, FILTER_VALIDATE_INT);
$depth = filter_var($argv[3] ?? 10_000, FILTER_VALIDATE_INT);
$indexSet = $argv[4] ?? 'current';
if (!in_array($driver, ['mysql', 'pgsql'], true)) {
    throw new InvalidArgumentException('Driver must be mysql or pgsql.');
}
if (!is_int($consumers) || !in_array($consumers, [2, 4, 8], true)) {
    throw new InvalidArgumentException('Consumers must be 2, 4, or 8.');
}
if (!is_int($depth) || $depth < 10_000 || $depth > 1_000_000) {
    throw new InvalidArgumentException('Depth must be between 10000 and 1000000.');
}
if (!in_array($indexSet, ['current', 'candidate', 'candidate-reclaim'], true)) {
    throw new InvalidArgumentException('Index set must be current, candidate, or candidate-reclaim.');
}
if (!function_exists('pcntl_fork')) {
    throw new RuntimeException('The DB contention benchmark requires pcntl.');
}

$database = getenv('IC_SERVICE_DATABASE');
$username = getenv('IC_SERVICE_USERNAME');
$password = getenv('IC_SERVICE_PASSWORD');
if (!is_string($database) || $database === '' || !is_string($username) || $username === '') {
    throw new RuntimeException('Configure IC_SERVICE_DATABASE and IC_SERVICE_USERNAME.');
}
$configuration = [
    'driver' => $driver,
    'host' => '127.0.0.1',
    'port' => $driver === 'mysql' ? 3306 : 5432,
    'database' => $database,
    'username' => $username,
    'password' => is_string($password) ? $password : '',
    'options' => [PDO::ATTR_TIMEOUT => 5],
];
$tables = [
    'queue' => 'omnibus_contention_messages',
    'failures' => 'omnibus_contention_failures',
    'workflows' => 'omnibus_contention_workflows',
    'items' => 'omnibus_contention_items',
];
$serializer = new JsonEnvelopeSerializer(
    new MessageCodecRegistry([
        new CallbackMessageCodec(
            'contention.v1',
            ContentionMessage::class,
            static fn(ContentionMessage $message): array => ['sequence' => $message->sequence],
            static fn(array $data): ContentionMessage => new ContentionMessage(
                (int) ($data['sequence'] ?? -1),
            ),
        ),
    ]),
    new StampCodecRegistry(CoreStampCodecs::all()),
);
$connection = new Connection(ConnectionConfig::fromArray($configuration));
foreach (array_reverse($tables) as $table) {
    $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
}

$reports = [];
$childProcess = false;

try {
    foreach (QueueSchema::statements(
        $driver,
        $tables['queue'],
        $tables['failures'],
        $tables['workflows'],
        $tables['items'],
    ) as $statement) {
        $connection->statement($statement);
    }
    if ($indexSet !== 'current') {
        if ($driver === 'mysql') {
            $connection->statement(
                'DROP INDEX omnibus_contention_messages_queue_idx ON omnibus_contention_messages',
            );
        } else {
            $connection->statement('DROP INDEX omnibus_contention_messages_queue_idx');
        }
        $connection->statement(
            'CREATE INDEX omnibus_contention_messages_ready_idx ON omnibus_contention_messages (queue_name, available_at, id)',
        );
        if ($indexSet === 'candidate-reclaim') {
            $connection->statement(
                'CREATE INDEX omnibus_contention_messages_reclaim_idx ON omnibus_contention_messages (queue_name, reserved_until)',
            );
        }
    }
    $transport = new DBLayerTransport(
        $connection,
        $serializer,
        new SystemClock(),
        $tables['queue'],
    );
    $oldClock = new class implements ClockInterface {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('-30 days');
        }
    };
    $oldTransport = new DBLayerTransport($connection, $serializer, $oldClock, $tables['queue']);
    for ($sequence = 0; $sequence < $depth; $sequence++) {
        $stamps = $sequence % 10 === 0 ? [new DelayStamp(1)] : [];
        $selectedTransport = $sequence % 10 === 4 ? $oldTransport : $transport;
        $selectedTransport->send(new Envelope(new ContentionMessage($sequence), $stamps), 'contention');
    }
    $stateRows = max(1, intdiv($depth, 10));
    $remaining = $stateRows;
    while ($remaining > 0) {
        $reserved = [...$transport->receive('contention', min(1_000, $remaining), 1)];
        if ($reserved === []) {
            break;
        }
        $remaining -= count($reserved);
    }
    $remaining = $stateRows;
    while ($remaining > 0) {
        $reserved = [...$transport->receive('contention', min(1_000, $remaining), 0.001)];
        if ($reserved === []) {
            break;
        }
        $remaining -= count($reserved);
    }
    usleep(2_000);

    $explainSql = $driver === 'mysql'
        ? 'EXPLAIN ANALYZE SELECT id FROM omnibus_contention_messages WHERE queue_name = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?) ORDER BY available_at, id LIMIT 100'
        : 'EXPLAIN (ANALYZE, BUFFERS) SELECT id FROM omnibus_contention_messages WHERE queue_name = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?) ORDER BY available_at, id LIMIT 100';
    $now = (int) floor(microtime(true) * 1_000_000);
    $executionPlan = $connection->select($explainSql, ['contention', $now, $now]);

    $run = bin2hex(random_bytes(8));
    $started = hrtime(true);
    $children = [];
    for ($worker = 0; $worker < $consumers; $worker++) {
        $report = sys_get_temp_dir() . '/omnibus-contention-' . $run . '-' . $worker . '.json';
        $reports[] = $report;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a benchmark consumer.');
        }
        if ($pid > 0) {
            $children[] = $pid;

            continue;
        }
        $childProcess = true;

        try {
            $workerConnection = new Connection(ConnectionConfig::fromArray($configuration));
            $workerTransport = new DBLayerTransport(
                $workerConnection,
                $serializer,
                new SystemClock(),
                $tables['queue'],
            );
            $acknowledged = 0;
            $receiveCalls = 0;
            $reservationCount = 0;
            $totalReceiveNanoseconds = 0;
            $receiveLatencies = [];
            while (true) {
                $reservationStarted = hrtime(true);
                $reservations = [...$workerTransport->receive('contention', 100, 30)];
                $elapsedReceive = hrtime(true) - $reservationStarted;
                $receiveCalls++;
                $reservationCount += count($reservations);
                $totalReceiveNanoseconds += $elapsedReceive;
                $receiveLatencies[] = $elapsedReceive;
                if ($reservations === []) {
                    if ($workerTransport->size('contention') === 0) {
                        break;
                    }

                    continue;
                }
                foreach ($reservations as $reservation) {
                    $workerTransport->acknowledge($reservation);
                    $acknowledged++;
                }
            }
            file_put_contents($report, json_encode([
                'acknowledged' => $acknowledged,
                'receive_calls' => $receiveCalls,
                'reservation_count' => $reservationCount,
                'total_receive_ns' => $totalReceiveNanoseconds,
                'receive_latencies_ns' => $receiveLatencies,
                'transaction_stats' => $workerConnection->transactionStats(),
            ], JSON_THROW_ON_ERROR));

            return;
        } catch (Throwable $failure) {
            file_put_contents($report, json_encode(['error' => $failure->getMessage()], JSON_THROW_ON_ERROR));

            return;
        }
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException(sprintf('Contention worker %d failed.', $pid));
        }
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    $acknowledged = $receiveCalls = $reservationCount = $totalReceiveNanoseconds = 0;
    $receiveLatencies = [];
    $workerMeanReceiveMilliseconds = [];
    $transactionStats = [];
    foreach ($reports as $report) {
        $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || isset($decoded['error'])) {
            throw new RuntimeException('A contention worker returned an invalid report.');
        }
        $acknowledged += (int) ($decoded['acknowledged'] ?? 0);
        $workerCalls = (int) ($decoded['receive_calls'] ?? 0);
        $workerNanoseconds = (int) ($decoded['total_receive_ns'] ?? 0);
        $receiveCalls += $workerCalls;
        $reservationCount += (int) ($decoded['reservation_count'] ?? 0);
        $totalReceiveNanoseconds += $workerNanoseconds;
        $workerMeanReceiveMilliseconds[] = $workerCalls > 0
            ? ($workerNanoseconds / $workerCalls) / 1_000_000
            : 0.0;
        foreach ($decoded['receive_latencies_ns'] ?? [] as $latency) {
            $receiveLatencies[] = (int) $latency;
        }
        $transactionStats[] = $decoded['transaction_stats'] ?? [];
    }
    sort($receiveLatencies);
    $percentile = static function (float $ratio) use ($receiveLatencies): float {
        if ($receiveLatencies === []) {
            return 0.0;
        }
        $index = max(0, (int) ceil(count($receiveLatencies) * $ratio) - 1);

        return $receiveLatencies[$index] / 1_000_000;
    };

    fwrite(STDOUT, json_encode([
        'driver' => $driver,
        'index_set' => $indexSet,
        'consumers' => $consumers,
        'depth' => $depth,
        'duration_seconds' => $elapsed,
        'messages_per_second' => $acknowledged / $elapsed,
        'receive_calls' => $receiveCalls,
        'reservation_count' => $reservationCount,
        'total_receive_ns' => $totalReceiveNanoseconds,
        'mean_receive_ms' => $receiveCalls > 0
            ? ($totalReceiveNanoseconds / $receiveCalls) / 1_000_000
            : 0.0,
        'mean_receive_ms_per_worker' => array_sum($workerMeanReceiveMilliseconds) / $consumers,
        'receive_p50_ms' => $percentile(0.50),
        'receive_p95_ms' => $percentile(0.95),
        'receive_p99_ms' => $percentile(0.99),
        'acknowledged' => $acknowledged,
        'duplicate_or_stale_settlements' => max(0, $acknowledged - $depth),
        'missing_settlements' => max(0, $depth - $acknowledged),
        'execution_plan' => $executionPlan,
        'transaction_stats' => $transactionStats,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    if (!$childProcess) {
        foreach ($reports as $report) {
            if (is_file($report)) {
                unlink($report);
            }
        }
        foreach (array_reverse($tables) as $table) {
            $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
}
