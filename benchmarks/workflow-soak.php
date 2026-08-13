<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Transport\Sender;
use Infocyph\Omnibus\Workflow\BatchCompleted;
use Infocyph\Omnibus\Workflow\InMemoryWorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowDispatchFailed;
use Infocyph\Omnibus\Workflow\WorkflowExecutionScope;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Psr\Clock\ClockInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class WorkflowSoakMessage
{
    public function __construct(public int $sequence) {}
}

final class WorkflowSoakClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class WorkflowSoakSender implements Sender
{
    /** @var list<Envelope> */
    public array $sent = [];

    private int $attempts = 0;

    public function __construct(private ?int $failAt = null) {}

    public function send(Envelope $envelope, string $queue): Envelope
    {
        if ($queue === '') {
            throw new InvalidArgumentException('Queue cannot be empty.');
        }
        $this->attempts++;
        if ($this->failAt === $this->attempts) {
            $this->failAt = null;

            throw new RuntimeException('Injected partial dispatch failure.');
        }
        $this->sent[] = $envelope;

        return $envelope;
    }
}

$cycles = filter_var($argv[1] ?? 100, FILTER_VALIDATE_INT);
if (!is_int($cycles) || $cycles < 1 || $cycles > 10_000) {
    throw new InvalidArgumentException('Cycles must be between 1 and 10000.');
}

$started = hrtime(true);
$reconciliationAttempts = 0;
$reconciliationErrors = 0;
$duplicateHandlerExecutions = 0;
$terminalRegressions = 0;
for ($cycle = 0; $cycle < $cycles; $cycle++) {
    $clock = new WorkflowSoakClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryWorkflowStore($clock);
    $sender = new WorkflowSoakSender($cycle % 10 === 0 ? 2 : null);
    $events = new EventDispatcher(new ListenerMap([
        BatchCompleted::class => [static function (): void {
            throw new RuntimeException('Injected terminal event failure.');
        }],
    ]));
    $coordinator = new WorkflowCoordinator($store, $sender, $events);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $items = [];
    for ($index = 0; $index < 100; $index++) {
        $items[] = new Envelope(new WorkflowSoakMessage(($cycle * 100) + $index));
    }

    try {
        $id = $coordinator->batch($items, 'soak');
    } catch (WorkflowDispatchFailed $failure) {
        $id = $failure->workflowId;
        $reconciliationAttempts++;

        try {
            $coordinator->dispatchPending($id, 100);
        } catch (Throwable) {
            $reconciliationErrors++;
        }
    }
    $handlerExecutions = 0;
    foreach ($sender->sent as $position => $envelope) {
        $scope->run($envelope, static function () use (&$handlerExecutions): void {
            $handlerExecutions++;
        });
        if ($position % 10 === 0) {
            $reconciliationAttempts++;
            $scope->run($envelope, static function () use (&$handlerExecutions): void {
                $handlerExecutions++;
            });
        }
        $coordinator->succeed($envelope);
        if ($position % 10 === 0) {
            $coordinator->succeed($envelope);
        }
    }
    $duplicateHandlerExecutions += max(0, $handlerExecutions - 100);
    $state = $store->find($id);
    if ($state === null || $state->status !== WorkflowStatus::Completed || $state->succeeded !== 100) {
        $reconciliationErrors++;
    }
    $coordinator->cancel($id);
    $coordinator->fail($sender->sent[0]);
    if ($store->find($id)?->status !== WorkflowStatus::Completed) {
        $terminalRegressions++;
    }

    if ($cycle % 10 === 0) {
        $expiryStore = new InMemoryWorkflowStore($clock);
        $expiryId = str_pad('expiry-' . $cycle, 26, 'x');
        $expiryStore->createBatch($expiryId, [new Envelope(new WorkflowSoakMessage($cycle))], 'soak');
        $expired = $expiryStore->claimPending($expiryId, leaseSeconds: 1)[0];
        $messageId = $expired->item->envelope->last(MessageIdStamp::class)?->id;
        $clock->advance('+2 seconds');
        $reclaimed = $expiryStore->claimPending($expiryId)[0];
        $reconciliationAttempts++;
        if ($reclaimed->item->envelope->last(MessageIdStamp::class)?->id !== $messageId) {
            $reconciliationErrors++;
        }
    }
}
$elapsed = (hrtime(true) - $started) / 1_000_000_000;
if ($reconciliationErrors > 0 || $duplicateHandlerExecutions > 0 || $terminalRegressions > 0) {
    throw new RuntimeException('Workflow reconciliation soak observed an invariant violation.');
}

fwrite(STDOUT, json_encode([
    'workflows' => $cycles,
    'items' => $cycles * 100,
    'duration_seconds' => $elapsed,
    'items_per_second' => ($cycles * 100) / $elapsed,
    'reconciliation_attempts' => $reconciliationAttempts,
    'reconciliation_errors' => $reconciliationErrors,
    'duplicate_handler_executions' => $duplicateHandlerExecutions,
    'terminal_regressions' => $terminalRegressions,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
