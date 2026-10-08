<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

$depth = filter_var($argv[1] ?? 100_000, FILTER_VALIDATE_INT);
if (!is_int($depth) || $depth < 10_000 || $depth > 1_000_000) {
    throw new InvalidArgumentException('History depth must be between 10000 and 1000000.');
}

$clock = new SystemClock();
$connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']));
foreach (QueueSchema::statements('sqlite') as $statement) {
    $connection->statement($statement);
}
$store = new DBLayerFailureStore(
    $connection,
    new JsonEnvelopeSerializer(new MessageCodecRegistry([]), new StampCodecRegistry(CoreStampCodecs::all())),
    clock: $clock,
);
$old = $clock->now()->modify('-30 days');
$started = hrtime(true);
for ($index = 0; $index < $depth; $index++) {
    $store->add(FailedMessage::undecodable(
        sprintf('history-%07d', $index),
        'retention',
        'untrusted-payload-' . $index,
        1,
        $old,
        RuntimeException::class,
        'historical failure',
    ));
}
$seedSeconds = (hrtime(true) - $started) / 1_000_000_000;
$active = $store->claimRetry('history-0000000');
$sent = $store->claimRetry('history-0000001');
if (!$store->markRetrySent($sent)) {
    throw new RuntimeException('Sent-state transition did not retain claim ownership.');
}
$beforePrune = hrtime(true);
$pruned = $store->prune($clock->now());
$pruneSeconds = (hrtime(true) - $beforePrune) / 1_000_000_000;
$remaining = $connection->scalar('SELECT COUNT(*) FROM omnibus_failures');
if ($pruned !== $depth - 2 || (int) $remaining !== 2
    || $store->find('history-0000000') === null || $store->find('history-0000001') === null) {
    throw new RuntimeException('Retention pruned or retained the wrong retry/claim rows.');
}
if (!$store->releaseRetry($active) || $store->prune($clock->now()) !== 1
    || !$store->removeRetried($sent)
    || (int) $connection->scalar('SELECT COUNT(*) FROM omnibus_failures') !== 0) {
    throw new RuntimeException('Released or sent rows were not conditionally reconciled.');
}

fwrite(STDOUT, json_encode([
    'driver' => 'sqlite',
    'history_depth' => $depth,
    'seed_seconds' => round($seedSeconds, 3),
    'prune_seconds' => round($pruneSeconds, 3),
    'pruned_unclaimed' => $pruned,
    'retained_retrying_and_sent' => 2,
    'final_failure_rows' => 0,
    'php_memory_peak_bytes' => memory_get_peak_usage(true),
    'note' => 'SQLite in-memory retention characterization only. Does not certify MySQL/PostgreSQL/SQL Server production retention.',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
