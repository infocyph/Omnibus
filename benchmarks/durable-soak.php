<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class DurableSoakMessage
{
    public function __construct(public int $sequence) {}
}

$cycles = filter_var($argv[1] ?? 100, FILTER_VALIDATE_INT);
if (!is_int($cycles) || $cycles < 1 || $cycles > 10_000) {
    throw new InvalidArgumentException('Cycles must be between 1 and 10000.');
}

$path = tempnam(sys_get_temp_dir(), 'omnibus-soak-');
if (!is_string($path)) {
    throw new RuntimeException('Unable to allocate a durable soak database.');
}

try {
    $configuration = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => $path,
    ]);
    $firstConnection = new Connection($configuration);
    foreach (QueueSchema::statements('sqlite') as $statement) {
        $firstConnection->statement($statement);
    }
    $serializer = new JsonEnvelopeSerializer(
        new MessageCodecRegistry([
            new CallbackMessageCodec(
                'soak.v1',
                DurableSoakMessage::class,
                static fn(DurableSoakMessage $message): array => ['sequence' => $message->sequence],
                static fn(array $data): DurableSoakMessage => new DurableSoakMessage(
                    (int) ($data['sequence'] ?? -1),
                ),
            ),
        ]),
        new StampCodecRegistry(CoreStampCodecs::all()),
    );
    $clock = new SystemClock();
    $first = new DBLayerTransport($firstConnection, $serializer, $clock);
    $second = new DBLayerTransport(new Connection($configuration), $serializer, $clock);
    $messages = $cycles * 100;
    for ($sequence = 0; $sequence < $messages; $sequence++) {
        $first->send(new Envelope(new DurableSoakMessage($sequence)), 'soak');
    }

    $seen = [];
    $started = hrtime(true);
    $turn = 0;
    while (count($seen) < $messages) {
        $consumer = $turn++ % 2 === 0 ? $first : $second;
        $reservations = [...$consumer->receive('soak', 100, 5)];
        if ($reservations === []) {
            throw new RuntimeException('Durable soak stalled before the queue drained.');
        }
        foreach ($reservations as $reservation) {
            $message = $reservation->envelope()->message;
            if (!$message instanceof DurableSoakMessage || isset($seen[$message->sequence])) {
                throw new RuntimeException('Durable soak observed malformed or duplicate delivery.');
            }
            $seen[$message->sequence] = true;
            $consumer->acknowledge($reservation);
        }
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    if ($first->size('soak') !== 0) {
        throw new RuntimeException('Durable soak left ready messages behind.');
    }

    fwrite(STDOUT, json_encode([
        'messages' => $messages,
        'consumers' => 2,
        'duration_seconds' => $elapsed,
        'messages_per_second' => $messages / $elapsed,
        'queue_depth' => 0,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Unable to remove the durable soak database.');
    }
}
