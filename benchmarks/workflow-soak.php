<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Workflow\InMemoryWorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowStatus;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class WorkflowSoakMessage
{
    public function __construct(public int $sequence) {}
}

$cycles = filter_var($argv[1] ?? 100, FILTER_VALIDATE_INT);
if (!is_int($cycles) || $cycles < 1 || $cycles > 10_000) {
    throw new InvalidArgumentException('Cycles must be between 1 and 10000.');
}

$started = hrtime(true);
for ($cycle = 0; $cycle < $cycles; $cycle++) {
    $store = new InMemoryWorkflowStore();
    $id = 'workflow-' . $cycle;
    $items = [];
    for ($index = 0; $index < 100; $index++) {
        $items[] = new Envelope(new WorkflowSoakMessage(($cycle * 100) + $index));
    }
    $store->createBatch($id, $items, 'soak');
    $claims = $store->claimPending($id, 100, 30);
    if (count($claims) !== 100) {
        throw new RuntimeException('Workflow soak did not claim the complete batch.');
    }
    foreach ($claims as $claim) {
        $store->confirmDispatched($id, $claim->item->itemId, $claim->token);
        $store->markHandled($id, $claim->item->index, $claim->item->itemId);
        $store->succeed($id, $claim->item->index);
    }
    $state = $store->find($id);
    if ($state === null || $state->status !== WorkflowStatus::Completed || $state->succeeded !== 100) {
        throw new RuntimeException('Workflow soak observed an invalid aggregate.');
    }
}
$elapsed = (hrtime(true) - $started) / 1_000_000_000;

fwrite(STDOUT, json_encode([
    'workflows' => $cycles,
    'items' => $cycles * 100,
    'duration_seconds' => $elapsed,
    'items_per_second' => ($cycles * 100) / $elapsed,
    'reconciliation_errors' => 0,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
