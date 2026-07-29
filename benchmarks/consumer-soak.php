<?php

declare(strict_types=1);

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Transport\InMemoryTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class SoakMessage
{
    public function __construct(public int $sequence) {}
}

$cycles = filter_var($argv[1] ?? 100, FILTER_VALIDATE_INT);
if (!is_int($cycles) || $cycles < 1 || $cycles > 10_000) {
    throw new InvalidArgumentException('Cycles must be between 1 and 10000.');
}

$clock = new SystemClock();
$transport = new InMemoryTransport($clock);
$handled = 0;
$consumer = new Consumer(
    $transport,
    new HandlerMap([
        SoakMessage::class => static function () use (&$handled): void {
            $handled++;
        },
    ]),
    new ExponentialRetryStrategy(initialDelaySeconds: 0),
    new InMemoryFailureStore(),
    $clock,
);
$baseline = memory_get_usage(true);
$started = microtime(true);
$perCycle = 100;

for ($cycle = 0; $cycle < $cycles; $cycle++) {
    for ($index = 0; $index < $perCycle; $index++) {
        $transport->send(new Envelope(new SoakMessage($cycle * $perCycle + $index)), 'soak');
    }
    $result = $consumer->run('soak', $perCycle);
    if ($result->succeeded !== $perCycle || $transport->size('soak') !== 0) {
        throw new RuntimeException(sprintf('Consumer soak failed during cycle %d.', $cycle + 1));
    }
}

$growth = memory_get_usage(true) - $baseline;
$maximumGrowth = 4 * 1024 * 1024;
if ($growth > $maximumGrowth) {
    throw new RuntimeException(sprintf('Consumer memory grew by %d bytes.', $growth));
}

fwrite(STDOUT, json_encode([
    'cycles' => $cycles,
    'messages' => $handled,
    'duration_seconds' => microtime(true) - $started,
    'memory_growth_bytes' => $growth,
    'queue_depth' => $transport->size('soak'),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
